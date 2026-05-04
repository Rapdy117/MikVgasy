package agentcore

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

var DefaultPublicKeyBase64 = ""

type LicensePayload struct {
	CustomerID string   `json:"customer_id"`
	DeviceID   string   `json:"device_id"`
	NASType    string   `json:"nas_type"`
	Edition    string   `json:"edition"`
	Features   []string `json:"features"`
	ExpiresAt  string   `json:"expires_at"`
	IssuedAt   string   `json:"issued_at"`
}

type LicenseEnvelope struct {
	Version   int            `json:"version"`
	Alg       string         `json:"alg"`
	Payload   LicensePayload `json:"payload"`
	Signature string         `json:"signature"`
}

type ActivationState struct {
	ActivatedAt string          `json:"activated_at"`
	LicenseKey  string          `json:"license_key"`
	License     LicenseEnvelope `json:"license"`
}

func NewKeyPair() (string, string, error) {
	publicKey, privateKey, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return "", "", err
	}
	return base64.StdEncoding.EncodeToString(privateKey), base64.StdEncoding.EncodeToString(publicKey), nil
}

func SignLicense(payload LicensePayload, privateKeyBase64 string) (string, LicenseEnvelope, error) {
	privateKey, err := decodePrivateKey(privateKeyBase64)
	if err != nil {
		return "", LicenseEnvelope{}, err
	}
	normalized, err := NormalizePayload(payload)
	if err != nil {
		return "", LicenseEnvelope{}, err
	}
	payloadBytes, err := canonicalPayloadJSON(normalized)
	if err != nil {
		return "", LicenseEnvelope{}, err
	}
	signature := ed25519.Sign(privateKey, payloadBytes)
	envelope := LicenseEnvelope{
		Version:   1,
		Alg:       "Ed25519",
		Payload:   normalized,
		Signature: base64.RawURLEncoding.EncodeToString(signature),
	}
	key := "RM1." + base64.RawURLEncoding.EncodeToString(payloadBytes) + "." + envelope.Signature
	return key, envelope, nil
}

func ParseLicense(input string) (LicenseEnvelope, error) {
	input = strings.TrimSpace(input)
	if input == "" {
		return LicenseEnvelope{}, errors.New("licence vide")
	}
	if strings.HasPrefix(input, "{") {
		var envelope LicenseEnvelope
		if err := json.Unmarshal([]byte(input), &envelope); err != nil {
			return LicenseEnvelope{}, err
		}
		return envelope, nil
	}
	parts := strings.Split(input, ".")
	if len(parts) != 3 || parts[0] != "RM1" {
		return LicenseEnvelope{}, errors.New("format licence invalide")
	}
	payloadBytes, err := base64.RawURLEncoding.DecodeString(parts[1])
	if err != nil {
		return LicenseEnvelope{}, errors.New("payload licence invalide")
	}
	var payload LicensePayload
	if err := json.Unmarshal(payloadBytes, &payload); err != nil {
		return LicenseEnvelope{}, err
	}
	return LicenseEnvelope{
		Version:   1,
		Alg:       "Ed25519",
		Payload:   payload,
		Signature: parts[2],
	}, nil
}

func LoadLicenseInput(input string) (string, error) {
	input = strings.TrimSpace(input)
	if input == "" {
		return "", errors.New("licence requise")
	}
	if info, err := os.Stat(input); err == nil && !info.IsDir() {
		bytes, err := os.ReadFile(input)
		if err != nil {
			return "", err
		}
		return strings.TrimSpace(string(bytes)), nil
	}
	return input, nil
}

func VerifyLicense(envelope LicenseEnvelope, publicKey ed25519.PublicKey, expectedDeviceID string) error {
	if envelope.Version != 1 || envelope.Alg != "Ed25519" {
		return errors.New("LICENSE_UNSUPPORTED_VERSION")
	}
	normalized, err := NormalizePayload(envelope.Payload)
	if err != nil {
		return err
	}
	payloadBytes, err := canonicalPayloadJSON(normalized)
	if err != nil {
		return err
	}
	signature, err := base64.RawURLEncoding.DecodeString(envelope.Signature)
	if err != nil || !ed25519.Verify(publicKey, payloadBytes, signature) {
		return errors.New("LICENSE_INVALID_SIGNATURE")
	}
	if expectedDeviceID != "" && !strings.EqualFold(normalized.DeviceID, expectedDeviceID) {
		return errors.New("LICENSE_DEVICE_MISMATCH")
	}
	if normalized.ExpiresAt != "never" {
		expiresAt, err := time.Parse("2006-01-02", normalized.ExpiresAt)
		if err != nil {
			return errors.New("LICENSE_INVALID_EXPIRY")
		}
		if time.Now().After(expiresAt.Add(24*time.Hour - time.Nanosecond)) {
			return errors.New("LICENSE_EXPIRED")
		}
	}
	return nil
}

func LoadPublicKey(appDir string) (ed25519.PublicKey, error) {
	value := strings.TrimSpace(os.Getenv("RM_AGENT_PUBLIC_KEY"))
	if value == "" && appDir != "" {
		if bytes, err := os.ReadFile(PublicKeyPath(appDir)); err == nil {
			value = strings.TrimSpace(string(bytes))
		}
	}
	if value == "" {
		value = strings.TrimSpace(DefaultPublicKeyBase64)
	}
	if value == "" {
		return nil, errors.New("PUBLIC_KEY_MISSING")
	}
	bytes, err := base64.StdEncoding.DecodeString(value)
	if err != nil {
		return nil, errors.New("PUBLIC_KEY_INVALID")
	}
	if len(bytes) != ed25519.PublicKeySize {
		return nil, errors.New("PUBLIC_KEY_INVALID")
	}
	return ed25519.PublicKey(bytes), nil
}

func SaveActivationState(appDir string, licenseKey string, envelope LicenseEnvelope) error {
	path := ActivationStatePath(appDir)
	if err := os.MkdirAll(filepath.Dir(path), 0750); err != nil {
		return err
	}
	state := ActivationState{
		ActivatedAt: time.Now().Format(time.RFC3339),
		LicenseKey:  licenseKey,
		License:     envelope,
	}
	bytes, err := json.MarshalIndent(state, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, bytes, 0640)
}

func LoadActivationState(appDir string) (ActivationState, error) {
	bytes, err := os.ReadFile(ActivationStatePath(appDir))
	if err != nil {
		return ActivationState{}, err
	}
	var state ActivationState
	if err := json.Unmarshal(bytes, &state); err != nil {
		return ActivationState{}, err
	}
	return state, nil
}

func NormalizePayload(payload LicensePayload) (LicensePayload, error) {
	payload.CustomerID = strings.TrimSpace(payload.CustomerID)
	payload.DeviceID = strings.ToUpper(strings.TrimSpace(payload.DeviceID))
	payload.NASType = strings.ToLower(strings.TrimSpace(payload.NASType))
	payload.Edition = strings.ToLower(strings.TrimSpace(payload.Edition))
	payload.ExpiresAt = strings.ToLower(strings.TrimSpace(payload.ExpiresAt))
	payload.IssuedAt = strings.TrimSpace(payload.IssuedAt)
	if payload.ExpiresAt == "" {
		payload.ExpiresAt = "never"
	}
	if payload.IssuedAt == "" {
		payload.IssuedAt = time.Now().Format("2006-01-02")
	}
	for index, feature := range payload.Features {
		payload.Features[index] = strings.ToLower(strings.TrimSpace(feature))
	}
	sort.Strings(payload.Features)
	if payload.CustomerID == "" {
		return payload, errors.New("CUSTOMER_REQUIRED")
	}
	if payload.DeviceID == "" {
		return payload, errors.New("DEVICE_REQUIRED")
	}
	if payload.NASType == "" {
		return payload, errors.New("NAS_TYPE_REQUIRED")
	}
	if payload.Edition == "" {
		return payload, errors.New("EDITION_REQUIRED")
	}
	if payload.ExpiresAt != "never" {
		if _, err := time.Parse("2006-01-02", payload.ExpiresAt); err != nil {
			return payload, errors.New("LICENSE_INVALID_EXPIRY")
		}
	}
	if _, err := time.Parse("2006-01-02", payload.IssuedAt); err != nil {
		return payload, errors.New("LICENSE_INVALID_ISSUED_AT")
	}
	return payload, nil
}

func canonicalPayloadJSON(payload LicensePayload) ([]byte, error) {
	return json.Marshal(payload)
}

func decodePrivateKey(privateKeyBase64 string) (ed25519.PrivateKey, error) {
	bytes, err := base64.StdEncoding.DecodeString(strings.TrimSpace(privateKeyBase64))
	if err != nil {
		return nil, errors.New("PRIVATE_KEY_INVALID")
	}
	if len(bytes) == ed25519.SeedSize {
		return ed25519.NewKeyFromSeed(bytes), nil
	}
	if len(bytes) != ed25519.PrivateKeySize {
		return nil, errors.New("PRIVATE_KEY_INVALID")
	}
	return ed25519.PrivateKey(bytes), nil
}
