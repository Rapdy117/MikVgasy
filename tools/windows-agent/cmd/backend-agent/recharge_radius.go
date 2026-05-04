package main

import (
	"bytes"
	"crypto/aes"
	"crypto/cipher"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	_ "github.com/go-sql-driver/mysql"

	"radius-manager/windows-agent/internal/agentcore"
)

type rechargeRequest struct {
	DeviceID string `json:"device_id"`
	Username string `json:"username"`
	Profile  string `json:"profile_value"`
	Mode     string `json:"mode"`
}

type dbConfig struct {
	Host   string `json:"host"`
	User   string `json:"user"`
	Pass   string `json:"pass"`
	DBName string `json:"dbname"`
}

type deviceStore struct {
	Devices []deviceRecord `json:"devices"`
}

type deviceRecord struct {
	ID          string `json:"id"`
	Type        string `json:"type"`
	Host        string `json:"host"`
	IP          string `json:"ip"`
	Business    string `json:"business_source"`
	Fingerprint string `json:"device_fingerprint"`
}

type userRow struct {
	ID                     int64
	Username               string
	Password               string
	NasID                  int64
	ProfileID              int64
	Status                 string
	ExpirationDate         sql.NullString
	SessionTimeout         sql.NullInt64
	DataLimit              sql.NullInt64
	CurrentCreditTime      sql.NullInt64
	CurrentCreditData      sql.NullInt64
	ImportedSessionSeconds sql.NullInt64
	ImportedDataBytes      sql.NullInt64
}

type profileRow struct {
	ID              int64
	Name            string
	SessionTimeout  sql.NullInt64
	ValidityTime    sql.NullInt64
	DataQuotaMB     sql.NullInt64
	RateLimit       sql.NullString
	SimultaneousUse sql.NullInt64
	IdleTimeout     sql.NullInt64
}

func applyRechargeResponse(appDir string, request rechargeRequest) (agentcore.Response, int) {
	if strings.TrimSpace(request.DeviceID) == "" || strings.TrimSpace(request.Username) == "" || strings.TrimSpace(request.Profile) == "" || strings.TrimSpace(request.Mode) == "" {
		return failResponse("RECHARGE_ARGUMENTS_INVALID", "device_id, username, profile_value et mode requis"), 2
	}
	if request.Mode != "replace_offer" && request.Mode != "extend_offer" && request.Mode != "accumulate_offer" {
		return failResponse("RECHARGE_MODE_INVALID", "mode de recharge invalide"), 2
	}

	device, err := loadDevice(appDir, request.DeviceID)
	if err != nil {
		return failResponse("DEVICE_NOT_FOUND", err.Error()), 1
	}
	if device.Business != "radius" {
		return failResponse("BACKEND_NOT_MIGRATED", "recharge MikroTik non migree dans backend-agent.exe"), 1
	}
	if device.Fingerprint == "" {
		return failResponse("DEVICE_FINGERPRINT_MISSING", "fingerprint device manquant"), 1
	}

	licenseID := formatDeviceID(device.Fingerprint, device.Type)
	if response, exitCode := authorizeActionResponse(appDir, "recharge-apply", licenseID, map[string]any{
		"device_id": request.DeviceID,
		"username":  request.Username,
		"mode":      request.Mode,
	}); exitCode != 0 {
		return response, exitCode
	}

	db, err := openAppDB(appDir)
	if err != nil {
		return failResponse("DB_CONNECT_FAILED", err.Error()), 1
	}
	defer db.Close()

	result, err := applyRadiusRecharge(db, device, request.Username, request.Profile, request.Mode)
	if err != nil {
		return failResponse("RECHARGE_FAILED", err.Error()), 1
	}

	return agentcore.Response{
		Success: true,
		Code:    "RECHARGE_APPLIED",
		Message: "recharge appliquee par backend-agent.exe",
		Data:    result,
	}, 0
}

func openAppDB(appDir string) (*sql.DB, error) {
	raw, err := os.ReadFile(filepath.Join(appDir, "config", "db.json"))
	if err != nil {
		return nil, err
	}
	var cfg dbConfig
	if err := json.Unmarshal(raw, &cfg); err != nil {
		return nil, err
	}
	pass, err := decryptAppValue(cfg.Pass)
	if err != nil {
		return nil, err
	}
	dsn := fmt.Sprintf("%s:%s@tcp(%s)/%s?charset=utf8mb4&parseTime=true", cfg.User, pass, cfg.Host, cfg.DBName)
	return sql.Open("mysql", dsn)
}

func loadDevice(appDir string, deviceID string) (deviceRecord, error) {
	raw, err := os.ReadFile(filepath.Join(appDir, "config", "opnsense.json"))
	if err != nil {
		return deviceRecord{}, err
	}
	var store deviceStore
	if err := json.Unmarshal(raw, &store); err != nil {
		return deviceRecord{}, err
	}
	for _, device := range store.Devices {
		if device.ID == deviceID {
			if device.Business == "" {
				device.Business = resolveDeviceBusiness(device.Type)
			}
			return device, nil
		}
	}
	return deviceRecord{}, fmt.Errorf("device introuvable: %s", deviceID)
}

func applyRadiusRecharge(db *sql.DB, device deviceRecord, username string, profileValue string, mode string) (map[string]any, error) {
	profileID, err := strconv.ParseInt(profileValue, 10, 64)
	if err != nil || profileID <= 0 {
		return nil, errors.New("profil invalide")
	}
	tx, err := db.Begin()
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()

	user, err := loadRechargeUser(tx, username)
	if err != nil {
		return nil, err
	}
	offer, err := loadRechargeProfile(tx, profileID)
	if err != nil {
		return nil, err
	}
	var current *profileRow
	if user.ProfileID > 0 {
		row, err := loadRechargeProfile(tx, user.ProfileID)
		if err == nil {
			current = &row
		}
	}

	projectedProfileID := offer.ID
	projectedSeconds := positiveInt64(offer.SessionTimeout)
	projectedMegabytes := positiveInt64(offer.DataQuotaMB)
	projectedExpiration := sql.NullString{}
	currentExpiration := parseDate(user.ExpirationDate)
	today := time.Now().UTC().Truncate(24 * time.Hour)

	accountingSeconds, accountingBytes, err := loadAccountingTotals(tx, username)
	if err != nil {
		return nil, err
	}
	baselineSeconds, baselineBytes, err := loadCounterBaseline(tx, "radius", username)
	if err != nil {
		return nil, err
	}
	remainingSeconds, remainingMegabytes := buildRemaining(user, current, accountingSeconds, accountingBytes, baselineSeconds, baselineBytes)

	if mode != "replace_offer" && currentExpiration != nil && !currentExpiration.Before(today) {
		projectedExpiration = sql.NullString{String: currentExpiration.Add(time.Duration(positiveInt64(offer.ValidityTime)) * time.Second).Format("2006-01-02"), Valid: true}
	}
	if mode == "extend_offer" {
		if currentExpiration == nil || currentExpiration.Before(today) {
			return nil, errors.New("le rechargement est disponible uniquement pour un compte non expire")
		}
		if user.ProfileID > 0 {
			projectedProfileID = user.ProfileID
		}
		projectedSeconds = remainingSeconds + positiveInt64(offer.SessionTimeout)
		projectedMegabytes = remainingMegabytes + positiveInt64(offer.DataQuotaMB)
		projectedExpiration = sql.NullString{String: currentExpiration.Add(time.Duration(positiveInt64(offer.ValidityTime)) * time.Second).Format("2006-01-02"), Valid: true}
	} else if mode == "accumulate_offer" {
		if current == nil || strings.TrimSpace(current.Name) != strings.TrimSpace(offer.Name) {
			return nil, errors.New("le cumul n est autorise que sur le meme profil")
		}
		if user.ProfileID > 0 {
			projectedProfileID = user.ProfileID
		}
		projectedSeconds = remainingSeconds + positiveInt64(offer.SessionTimeout)
		projectedMegabytes = remainingMegabytes + positiveInt64(offer.DataQuotaMB)
		if currentExpiration != nil && !currentExpiration.Before(today) {
			projectedExpiration = sql.NullString{String: currentExpiration.Add(time.Duration(positiveInt64(offer.ValidityTime)) * time.Second).Format("2006-01-02"), Valid: true}
		}
	}

	nasID := user.NasID
	if nasID <= 0 {
		nasID, err = resolveNasIDByDevice(tx, device)
		if err != nil {
			return nil, err
		}
	}
	nasType, err := loadNasType(tx, nasID)
	if err != nil {
		return nil, err
	}
	if resolveNasBusiness(nasType) != "radius" {
		return nil, errors.New("le NAS cible ne passe pas par backend RADIUS")
	}
	effective, err := loadRechargeProfile(tx, projectedProfileID)
	if err != nil {
		return nil, err
	}

	_, err = tx.Exec(`
		UPDATE users
		SET profile_id = ?, nas_id = ?, session_timeout = ?, data_limit = ?, current_credit_time = ?, current_credit_data = ?, expiration_date = ?
		WHERE id = ?
	`, effective.ID, nasID, nullablePositive(projectedSeconds), nullablePositive(projectedMegabytes), projectedSeconds, projectedMegabytes*1024*1024, nullableString(projectedExpiration), user.ID)
	if err != nil {
		return nil, err
	}
	if err := upsertCounterBaseline(tx, "radius", username, accountingSeconds, accountingBytes); err != nil {
		return nil, err
	}
	if err := updateRadiusProjection(tx, user, effective, projectedSeconds, projectedMegabytes, projectedExpiration, nasType); err != nil {
		return nil, err
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	expirationLabel := "-"
	if projectedExpiration.Valid {
		expirationLabel = projectedExpiration.String
	}
	return map[string]any{
		"profile_name": effective.Name,
		"projected": map[string]any{
			"profile":    effective.Name,
			"time_limit": strconv.FormatInt(projectedSeconds, 10),
			"data_limit": strconv.FormatInt(projectedMegabytes, 10),
			"expiration": expirationLabel,
		},
	}, nil
}

func loadRechargeUser(tx *sql.Tx, username string) (userRow, error) {
	row := tx.QueryRow(`
		SELECT id, username, password, COALESCE(nas_id, 0), COALESCE(profile_id, 0), status, expiration_date, session_timeout, data_limit, current_credit_time, current_credit_data, imported_session_total_seconds, imported_data_consumed_bytes
		FROM users WHERE username = ? LIMIT 1
	`, username)
	var user userRow
	err := row.Scan(&user.ID, &user.Username, &user.Password, &user.NasID, &user.ProfileID, &user.Status, &user.ExpirationDate, &user.SessionTimeout, &user.DataLimit, &user.CurrentCreditTime, &user.CurrentCreditData, &user.ImportedSessionSeconds, &user.ImportedDataBytes)
	if err == sql.ErrNoRows {
		return userRow{}, errors.New("utilisateur introuvable")
	}
	return user, err
}

func loadRechargeProfile(tx *sql.Tx, id int64) (profileRow, error) {
	row := tx.QueryRow(`
		SELECT id, name, session_timeout, validity_time, data_quota_mb, rate_limit, simultaneous_use, idle_timeout
		FROM profiles WHERE id = ? LIMIT 1
	`, id)
	var profile profileRow
	err := row.Scan(&profile.ID, &profile.Name, &profile.SessionTimeout, &profile.ValidityTime, &profile.DataQuotaMB, &profile.RateLimit, &profile.SimultaneousUse, &profile.IdleTimeout)
	if err == sql.ErrNoRows {
		return profileRow{}, errors.New("profil introuvable")
	}
	return profile, err
}

func loadAccountingTotals(tx *sql.Tx, username string) (int64, int64, error) {
	row := tx.QueryRow(`SELECT COALESCE(SUM(acctsessiontime), 0), COALESCE(SUM(acctinputoctets), 0) + COALESCE(SUM(acctoutputoctets), 0) FROM radacct WHERE username = ?`, username)
	var seconds, bytes int64
	return seconds, bytes, row.Scan(&seconds, &bytes)
}

func loadCounterBaseline(tx *sql.Tx, deviceID string, username string) (int64, int64, error) {
	ensureBaseline := `CREATE TABLE IF NOT EXISTS user_counter_baselines (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		device_id VARCHAR(191) NOT NULL,
		username VARCHAR(191) NOT NULL,
		imported_session_total_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
		imported_data_consumed_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
		created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY uniq_device_username (device_id, username),
		KEY idx_username (username)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
	if _, err := tx.Exec(ensureBaseline); err != nil {
		return 0, 0, err
	}
	row := tx.QueryRow(`SELECT imported_session_total_seconds, imported_data_consumed_bytes FROM user_counter_baselines WHERE device_id = ? AND username = ? LIMIT 1`, deviceID, username)
	var seconds, bytes int64
	err := row.Scan(&seconds, &bytes)
	if err == sql.ErrNoRows {
		return 0, 0, nil
	}
	return seconds, bytes, err
}

func upsertCounterBaseline(tx *sql.Tx, deviceID string, username string, seconds int64, bytes int64) error {
	_, err := tx.Exec(`
		INSERT INTO user_counter_baselines (device_id, username, imported_session_total_seconds, imported_data_consumed_bytes)
		VALUES (?, ?, ?, ?)
		ON DUPLICATE KEY UPDATE imported_session_total_seconds = VALUES(imported_session_total_seconds), imported_data_consumed_bytes = VALUES(imported_data_consumed_bytes)
	`, deviceID, username, max64(seconds, 0), max64(bytes, 0))
	return err
}

func updateRadiusProjection(tx *sql.Tx, user userRow, profile profileRow, sessionSeconds int64, dataMegabytes int64, expiration sql.NullString, nasType string) error {
	password, err := decryptAppValue(user.Password)
	if err != nil {
		return err
	}
	username := user.Username
	status := strings.ToLower(strings.TrimSpace(user.Status))
	if _, err := tx.Exec(`DELETE FROM radcheck WHERE username = ? AND attribute IN ('Auth-Type', 'Cleartext-Password')`, username); err != nil {
		return err
	}
	if status == "disabled" || status == "expired" {
		if _, err := tx.Exec(`INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Auth-Type', ':=', 'Reject')`, username); err != nil {
			return err
		}
	}
	if _, err := tx.Exec(`INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)`, username, password); err != nil {
		return err
	}
	if _, err := tx.Exec(`DELETE FROM radusergroup WHERE username = ?`, username); err != nil {
		return err
	}
	if _, err := tx.Exec(`INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)`, username, profile.Name); err != nil {
		return err
	}
	if _, err := tx.Exec(`DELETE FROM radreply WHERE username = ?`, username); err != nil {
		return err
	}
	groupAttrs, err := loadGroupAttrs(tx, profile.Name)
	if err != nil {
		return err
	}
	if sessionSeconds > 0 {
		if err := insertUserReplyIfAllowed(tx, username, "Session-Timeout", strconv.FormatInt(sessionSeconds, 10), groupAttrs, true); err != nil {
			return err
		}
	}
	if positiveInt64(profile.SimultaneousUse) > 0 {
		if err := insertUserReplyIfAllowed(tx, username, "Simultaneous-Use", strconv.FormatInt(positiveInt64(profile.SimultaneousUse), 10), groupAttrs, false); err != nil {
			return err
		}
	}
	if positiveInt64(profile.IdleTimeout) > 0 {
		if err := insertUserReplyIfAllowed(tx, username, "Idle-Timeout", strconv.FormatInt(positiveInt64(profile.IdleTimeout), 10), groupAttrs, false); err != nil {
			return err
		}
	}
	if dataMegabytes > 0 {
		if err := insertUserReplyIfAllowed(tx, username, "Max-Octets", strconv.FormatInt(dataMegabytes*1024*1024, 10), groupAttrs, true); err != nil {
			return err
		}
	}
	if expiration.Valid && expiration.String != "" {
		if err := insertUserReply(tx, username, "Expiration", expiration.String); err != nil {
			return err
		}
	}
	return applyUserRateLimitGo(tx, username, nullString(profile.RateLimit), nasType, groupAttrs)
}

func loadGroupAttrs(tx *sql.Tx, group string) (map[string]bool, error) {
	rows, err := tx.Query(`SELECT attribute FROM radgroupreply WHERE groupname = ?`, group)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	attrs := map[string]bool{}
	for rows.Next() {
		var attr string
		if err := rows.Scan(&attr); err != nil {
			return nil, err
		}
		attrs[strings.TrimSpace(attr)] = true
	}
	return attrs, rows.Err()
}

func insertUserReplyIfAllowed(tx *sql.Tx, username string, attr string, value string, groupAttrs map[string]bool, force bool) error {
	if !force && groupAttrs[attr] {
		return nil
	}
	return insertUserReply(tx, username, attr, value)
}

func insertUserReply(tx *sql.Tx, username string, attr string, value any) error {
	_, err := tx.Exec(`INSERT INTO radreply (username, attribute, op, value) VALUES (?, ?, ':=', ?)`, username, attr, value)
	return err
}

func applyUserRateLimitGo(tx *sql.Tx, username string, rateLimit string, nasType string, groupAttrs map[string]bool) error {
	if strings.TrimSpace(rateLimit) == "" || !strings.Contains(rateLimit, "/") {
		return nil
	}
	if groupAttrs["WISPr-Bandwidth-Max-Down"] || groupAttrs["WISPr-Bandwidth-Max-Up"] {
		return nil
	}
	parts := strings.SplitN(rateLimit, "/", 2)
	if err := insertUserReply(tx, username, "WISPr-Bandwidth-Max-Up", strconv.FormatInt(convertToBitsGo(parts[0]), 10)); err != nil {
		return err
	}
	return insertUserReply(tx, username, "WISPr-Bandwidth-Max-Down", strconv.FormatInt(convertToBitsGo(parts[1]), 10))
}

func resolveNasIDByDevice(tx *sql.Tx, device deviceRecord) (int64, error) {
	address := extractDeviceAddress(device.Host)
	if address == "" {
		address = device.IP
	}
	var id int64
	err := tx.QueryRow(`SELECT id FROM nas WHERE nasname = ? LIMIT 1`, address).Scan(&id)
	if err == nil {
		return id, nil
	}
	err = tx.QueryRow(`SELECT id FROM nas WHERE nasname LIKE ? LIMIT 1`, "%"+address+"%").Scan(&id)
	if err == nil {
		return id, nil
	}
	return 0, errors.New("NAS introuvable pour cet utilisateur")
}

func loadNasType(tx *sql.Tx, nasID int64) (string, error) {
	var nasType string
	err := tx.QueryRow(`SELECT type FROM nas WHERE id = ? LIMIT 1`, nasID).Scan(&nasType)
	if err == sql.ErrNoRows {
		return "", errors.New("NAS introuvable")
	}
	return normalizeNasTypeGo(nasType), err
}

func buildRemaining(user userRow, profile *profileRow, accountingSeconds int64, accountingBytes int64, baselineSeconds int64, baselineBytes int64) (int64, int64) {
	displaySeconds := positiveInt64(user.ImportedSessionSeconds) + max64(0, accountingSeconds-baselineSeconds)
	displayBytes := positiveInt64(user.ImportedDataBytes) + max64(0, accountingBytes-baselineBytes)
	displayMegabytes := int64(math.Round(float64(displayBytes) / 1024 / 1024))
	allocatedSeconds := firstPositive(positiveInt64(user.CurrentCreditTime), positiveInt64(user.SessionTimeout), profileSession(profile))
	allocatedMegabytes := firstPositive(normalizeStoredCreditDataToMegabytes(positiveInt64(user.CurrentCreditData)), positiveInt64(user.DataLimit), profileData(profile))
	return max64(0, allocatedSeconds-displaySeconds), max64(0, allocatedMegabytes-displayMegabytes)
}

func decryptAppValue(value string) (string, error) {
	if value == "" || !strings.HasPrefix(value, "enc:") {
		return value, nil
	}
	raw, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(value, "enc:"))
	if err != nil {
		return value, nil
	}
	parts := bytes.SplitN(raw, []byte(":"), 2)
	if len(parts) != 2 {
		return value, nil
	}
	iv, err := hex.DecodeString(string(parts[0]))
	if err != nil {
		return value, nil
	}
	ciphertext, err := base64.StdEncoding.DecodeString(string(parts[1]))
	if err != nil {
		return value, nil
	}
	secret := sha256.Sum256([]byte("RadiusMgr|X9f2K1mP|Qz7N3wRt2026"))
	keyMaterial := append(secret[:], []byte("|AES256|CRYPT")...)
	key := sha256.Sum256(keyMaterial)
	block, err := aes.NewCipher(key[:])
	if err != nil {
		return "", err
	}
	if len(ciphertext)%aes.BlockSize != 0 {
		return value, nil
	}
	mode := cipher.NewCBCDecrypter(block, iv)
	plain := make([]byte, len(ciphertext))
	mode.CryptBlocks(plain, ciphertext)
	plain = pkcs7Unpad(plain)
	return string(plain), nil
}

func pkcs7Unpad(data []byte) []byte {
	if len(data) == 0 {
		return data
	}
	n := int(data[len(data)-1])
	if n <= 0 || n > len(data) {
		return data
	}
	return data[:len(data)-n]
}

func formatDeviceID(fingerprint string, deviceType string) string {
	prefix := "DEV"
	switch strings.ToLower(strings.TrimSpace(deviceType)) {
	case "mikrotik":
		prefix = "MK"
	case "opnsense":
		prefix = "OPN"
	case "radius":
		prefix = "RAD"
	}
	hexPart := strings.ToUpper(fingerprint)
	if len(hexPart) > 12 {
		hexPart = hexPart[:12]
	}
	for len(hexPart) < 12 {
		hexPart += "0"
	}
	return fmt.Sprintf("%s-%s-%s-%s", prefix, hexPart[:4], hexPart[4:8], hexPart[8:12])
}

func extractDeviceAddress(host string) string {
	parsed, err := url.Parse(host)
	if err == nil && parsed.Hostname() != "" {
		return parsed.Hostname()
	}
	return strings.TrimSpace(strings.Split(host, "/")[0])
}

func normalizeNasTypeGo(value string) string {
	switch strings.ToLower(strings.TrimSpace(value)) {
	case "mikrotik":
		return "mikrotik"
	case "opnsense":
		return "opnsense"
	default:
		return "radius"
	}
}

func resolveNasBusiness(value string) string {
	if normalizeNasTypeGo(value) == "mikrotik" {
		return "mikrotik_local"
	}
	return "radius"
}

func resolveDeviceBusiness(value string) string {
	return resolveNasBusiness(value)
}

func parseDate(value sql.NullString) *time.Time {
	if !value.Valid || strings.TrimSpace(value.String) == "" {
		return nil
	}
	parsed, err := time.Parse("2006-01-02", value.String[:min(len(value.String), 10)])
	if err != nil {
		return nil
	}
	return &parsed
}

func positiveInt64(value sql.NullInt64) int64 {
	if !value.Valid || value.Int64 < 0 {
		return 0
	}
	return value.Int64
}

func nullablePositive(value int64) any {
	if value > 0 {
		return value
	}
	return nil
}

func nullableString(value sql.NullString) any {
	if value.Valid {
		return value.String
	}
	return nil
}

func nullString(value sql.NullString) string {
	if value.Valid {
		return value.String
	}
	return ""
}

func profileSession(profile *profileRow) int64 {
	if profile == nil {
		return 0
	}
	return positiveInt64(profile.SessionTimeout)
}

func profileData(profile *profileRow) int64 {
	if profile == nil {
		return 0
	}
	return positiveInt64(profile.DataQuotaMB)
}

func normalizeStoredCreditDataToMegabytes(value int64) int64 {
	if value > 1024*1024 {
		return int64(math.Round(float64(value) / 1024 / 1024))
	}
	return value
}

func firstPositive(values ...int64) int64 {
	for _, value := range values {
		if value > 0 {
			return value
		}
	}
	return 0
}

func max64(a int64, b int64) int64 {
	if a > b {
		return a
	}
	return b
}

func convertToBitsGo(rate string) int64 {
	value := strings.ToUpper(strings.TrimSpace(rate))
	multiplier := int64(1)
	if strings.Contains(value, "M") {
		multiplier = 1000000
	}
	if strings.Contains(value, "K") {
		multiplier = 1000
	}
	digits := ""
	for _, r := range value {
		if r >= '0' && r <= '9' {
			digits += string(r)
		}
	}
	parsed, _ := strconv.ParseInt(digits, 10, 64)
	return parsed * multiplier
}
