package main

import (
	"encoding/json"
	"flag"
	"log"
	"net/http"
	"os"
	"strconv"
	"time"

	"radius-manager/windows-agent/internal/agentcore"
)

type serviceRequest struct {
	Action   string         `json:"action"`
	DeviceID string         `json:"device_id"`
	Payload  map[string]any `json:"payload"`
}

func main() {
	if len(os.Args) < 2 {
		agentcore.Fail("COMMAND_REQUIRED", "commande requise", 2)
	}
	switch os.Args[1] {
	case "serve":
		serve(os.Args[2:])
	case "apply-recharge":
		applyRechargeCLI(os.Args[2:])
	case "check-license":
		checkLicense(os.Args[2:])
	case "check-integrity":
		checkIntegrity(os.Args[2:])
	case "authorize-action":
		authorizeAction(os.Args[2:])
	case "activate-license":
		activateLicense(os.Args[2:])
	default:
		agentcore.Fail("COMMAND_UNKNOWN", "commande inconnue", 2)
	}
}

func serve(args []string) {
	flags := flag.NewFlagSet("serve", flag.ContinueOnError)
	appDirRaw := flags.String("app-dir", "", "racine application")
	listen := flags.String("listen", "127.0.0.1:8765", "adresse ecoute locale")
	token := flags.String("token", "", "token service local")
	if err := flags.Parse(args); err != nil {
		agentcore.Fail("ARGUMENTS_INVALID", err.Error(), 2)
	}
	appDir, err := agentcore.CleanAppDir(*appDirRaw)
	if err != nil {
		agentcore.Fail("APP_DIR_INVALID", err.Error(), 2)
	}
	if *token == "" {
		agentcore.Fail("SERVICE_TOKEN_REQUIRED", "token service requis", 2)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/v1/health", func(w http.ResponseWriter, r *http.Request) {
		writeHTTP(w, agentcore.Response{
			Success: true,
			Code:    "SERVICE_READY",
			Message: "backend-agent service actif",
			Data:    map[string]any{},
		}, http.StatusOK)
	})
	mux.HandleFunc("/v1/check-license", withToken(*token, func(w http.ResponseWriter, r *http.Request) {
		request, ok := decodeServiceRequest(w, r)
		if !ok {
			return
		}
		response, exitCode := checkLicenseResponse(appDir, request.DeviceID)
		writeHTTP(w, response, serviceHTTPStatus(exitCode))
	}))
	mux.HandleFunc("/v1/check-integrity", withToken(*token, func(w http.ResponseWriter, r *http.Request) {
		response, exitCode := checkIntegrityResponse(appDir)
		writeHTTP(w, response, serviceHTTPStatus(exitCode))
	}))
	mux.HandleFunc("/v1/authorize-action", withToken(*token, func(w http.ResponseWriter, r *http.Request) {
		request, ok := decodeServiceRequest(w, r)
		if !ok {
			return
		}
		response, exitCode := authorizeActionResponse(appDir, request.Action, request.DeviceID, request.Payload)
		writeHTTP(w, response, serviceHTTPStatus(exitCode))
	}))
	mux.HandleFunc("/v1/apply-recharge", withToken(*token, func(w http.ResponseWriter, r *http.Request) {
		request, ok := decodeServiceRequest(w, r)
		if !ok {
			return
		}
		recharge := rechargeRequest{
			DeviceID: stringValue(request.Payload["device_id"]),
			Username: stringValue(request.Payload["username"]),
			Profile:  stringValue(request.Payload["profile_value"]),
			Mode:     stringValue(request.Payload["mode"]),
		}
		response, exitCode := applyRechargeResponse(appDir, recharge)
		writeHTTP(w, response, serviceHTTPStatus(exitCode))
	}))

	server := &http.Server{
		Addr:              *listen,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
	}
	log.Fatal(server.ListenAndServe())
}

func withToken(token string, next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeHTTP(w, agentcore.Response{Success: false, Code: "METHOD_NOT_ALLOWED", Message: "methode non autorisee", Data: map[string]any{}}, http.StatusMethodNotAllowed)
			return
		}
		if r.Header.Get("X-Agent-Token") != token {
			writeHTTP(w, agentcore.Response{Success: false, Code: "SERVICE_TOKEN_INVALID", Message: "token service invalide", Data: map[string]any{}}, http.StatusForbidden)
			return
		}
		next(w, r)
	}
}

func decodeServiceRequest(w http.ResponseWriter, r *http.Request) (serviceRequest, bool) {
	defer r.Body.Close()
	var request serviceRequest
	decoder := json.NewDecoder(r.Body)
	if err := decoder.Decode(&request); err != nil {
		writeHTTP(w, agentcore.Response{Success: false, Code: "PAYLOAD_INVALID", Message: "payload JSON invalide", Data: map[string]any{}}, http.StatusBadRequest)
		return serviceRequest{}, false
	}
	if request.Payload == nil {
		request.Payload = map[string]any{}
	}
	return request, true
}

func writeHTTP(w http.ResponseWriter, response agentcore.Response, status int) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	encoder := json.NewEncoder(w)
	encoder.SetEscapeHTML(false)
	_ = encoder.Encode(response)
}

func serviceHTTPStatus(exitCode int) int {
	if exitCode == 0 {
		return http.StatusOK
	}
	if exitCode == 2 {
		return http.StatusBadRequest
	}
	return http.StatusForbidden
}

func stringValue(value any) string {
	switch typed := value.(type) {
	case string:
		return typed
	case float64:
		return strconv.FormatInt(int64(typed), 10)
	default:
		return ""
	}
}

func applyRechargeCLI(args []string) {
	flags := flag.NewFlagSet("apply-recharge", flag.ContinueOnError)
	deviceID := flags.String("device-id", "", "device store id")
	username := flags.String("username", "", "username")
	profile := flags.String("profile-value", "", "profile id ou nom")
	mode := flags.String("mode", "", "mode recharge")
	appDirRaw := flags.String("app-dir", "", "racine application")
	if err := flags.Parse(args); err != nil {
		agentcore.Fail("ARGUMENTS_INVALID", err.Error(), 2)
	}
	appDir, err := agentcore.CleanAppDir(*appDirRaw)
	if err != nil {
		agentcore.Fail("APP_DIR_INVALID", err.Error(), 2)
	}
	response, exitCode := applyRechargeResponse(appDir, rechargeRequest{
		DeviceID: *deviceID,
		Username: *username,
		Profile:  *profile,
		Mode:     *mode,
	})
	agentcore.Exit(response, exitCode)
}

func checkLicense(args []string) {
	flags := flag.NewFlagSet("check-license", flag.ContinueOnError)
	deviceID := flags.String("device-id", "", "device id")
	appDirRaw := flags.String("app-dir", "", "racine application")
	if err := flags.Parse(args); err != nil {
		agentcore.Fail("ARGUMENTS_INVALID", err.Error(), 2)
	}
	appDir, err := agentcore.CleanAppDir(*appDirRaw)
	if err != nil {
		agentcore.Fail("APP_DIR_INVALID", err.Error(), 2)
	}
	response, exitCode := checkLicenseResponse(appDir, *deviceID)
	agentcore.Exit(response, exitCode)
}

func checkLicenseResponse(appDir string, deviceID string) (agentcore.Response, int) {
	state, err := agentcore.LoadActivationState(appDir)
	if err != nil {
		return failResponse("LICENSE_NOT_ACTIVATED", "aucune activation locale"), 1
	}
	publicKey, err := agentcore.LoadPublicKey(appDir)
	if err != nil {
		return failResponse(err.Error(), "cle publique indisponible ou invalide"), 2
	}
	if err := agentcore.VerifyLicense(state.License, publicKey, deviceID); err != nil {
		return failResponse(err.Error(), "licence refusee"), 1
	}
	return agentcore.Response{Success: true, Code: "LICENSE_ACTIVE", Message: "licence active", Data: map[string]any{
		"device_id":  state.License.Payload.DeviceID,
		"customer":   state.License.Payload.CustomerID,
		"edition":    state.License.Payload.Edition,
		"expires_at": state.License.Payload.ExpiresAt,
	}}, 0
}

func checkIntegrity(args []string) {
	flags := flag.NewFlagSet("check-integrity", flag.ContinueOnError)
	appDirRaw := flags.String("app-dir", "", "racine application")
	if err := flags.Parse(args); err != nil {
		agentcore.Fail("ARGUMENTS_INVALID", err.Error(), 2)
	}
	appDir, err := agentcore.CleanAppDir(*appDirRaw)
	if err != nil {
		agentcore.Fail("APP_DIR_INVALID", err.Error(), 2)
	}
	response, exitCode := checkIntegrityResponse(appDir)
	agentcore.Exit(response, exitCode)
}

func checkIntegrityResponse(appDir string) (agentcore.Response, int) {
	if err := agentcore.CheckIntegrity(appDir); err != nil {
		return failResponse(err.Error(), "integrite refusee"), 1
	}
	return agentcore.Response{Success: true, Code: "INTEGRITY_OK", Message: "integrite valide", Data: map[string]any{}}, 0
}

func authorizeAction(args []string) {
	flags := flag.NewFlagSet("authorize-action", flag.ContinueOnError)
	action := flags.String("action", "", "action sensible")
	deviceID := flags.String("device-id", "", "device id")
	payloadRaw := flags.String("payload", "{}", "payload JSON")
	appDirRaw := flags.String("app-dir", "", "racine application")
	if err := flags.Parse(args); err != nil {
		agentcore.Fail("ARGUMENTS_INVALID", err.Error(), 2)
	}
	if *action == "" {
		agentcore.Fail("ACTION_REQUIRED", "action requise", 2)
	}
	if !isAllowedAction(*action) {
		agentcore.Fail("ACTION_DENIED", "action non autorisee par backend-agent.exe", 1)
	}
	var payload map[string]any
	if err := json.Unmarshal([]byte(*payloadRaw), &payload); err != nil {
		agentcore.Fail("PAYLOAD_INVALID", "payload JSON invalide", 2)
	}
	appDir, err := agentcore.CleanAppDir(*appDirRaw)
	if err != nil {
		agentcore.Fail("APP_DIR_INVALID", err.Error(), 2)
	}
	response, exitCode := authorizeActionResponse(appDir, *action, *deviceID, payload)
	agentcore.Exit(response, exitCode)
}

func authorizeActionResponse(appDir string, action string, deviceID string, payload map[string]any) (agentcore.Response, int) {
	if action == "" {
		return failResponse("ACTION_REQUIRED", "action requise"), 2
	}
	if !isAllowedAction(action) {
		return failResponse("ACTION_DENIED", "action non autorisee par backend-agent.exe"), 1
	}
	state, err := agentcore.LoadActivationState(appDir)
	if err != nil {
		return failResponse("LICENSE_NOT_ACTIVATED", "aucune activation locale"), 1
	}
	publicKey, err := agentcore.LoadPublicKey(appDir)
	if err != nil {
		return failResponse(err.Error(), "cle publique indisponible ou invalide"), 2
	}
	if err := agentcore.VerifyLicense(state.License, publicKey, deviceID); err != nil {
		return failResponse(err.Error(), "licence refusee"), 1
	}
	if err := agentcore.CheckIntegrity(appDir); err != nil {
		return failResponse(err.Error(), "integrite refusee"), 1
	}
	_ = payload
	return agentcore.Response{Success: true, Code: "ACTION_AUTHORIZED", Message: "action autorisee", Data: map[string]any{
		"action":    action,
		"device_id": deviceID,
	}}, 0
}

func failResponse(code string, message string) agentcore.Response {
	return agentcore.Response{Success: false, Code: code, Message: message, Data: map[string]any{}}
}

func isAllowedAction(action string) bool {
	allowed := map[string]bool{
		"voucher-apply-batch":  true,
		"user-create":          true,
		"user-update":          true,
		"user-delete":          true,
		"mikrotik-update-user": true,
		"mikrotik-delete-user": true,
		"profile-create":       true,
		"profile-update":       true,
		"profile-delete":       true,
		"recharge-apply":       true,
		"standard-import":      true,
	}
	return allowed[action]
}

func activateLicense(args []string) {
	flags := flag.NewFlagSet("activate-license", flag.ContinueOnError)
	licenseInput := flags.String("key", "", "fichier ou cle licence")
	appDirRaw := flags.String("app-dir", "", "racine application")
	if err := flags.Parse(args); err != nil {
		agentcore.Fail("ARGUMENTS_INVALID", err.Error(), 2)
	}
	appDir, err := agentcore.CleanAppDir(*appDirRaw)
	if err != nil {
		agentcore.Fail("APP_DIR_INVALID", err.Error(), 2)
	}
	rawLicense, err := agentcore.LoadLicenseInput(*licenseInput)
	if err != nil {
		agentcore.Fail("LICENSE_READ_FAILED", err.Error(), 2)
	}
	envelope, err := agentcore.ParseLicense(rawLicense)
	if err != nil {
		agentcore.Fail("LICENSE_INVALID_FORMAT", err.Error(), 2)
	}
	publicKey, err := agentcore.LoadPublicKey(appDir)
	if err != nil {
		agentcore.Fail(err.Error(), "cle publique indisponible ou invalide", 2)
	}
	if err := agentcore.VerifyLicense(envelope, publicKey, ""); err != nil {
		agentcore.Fail(err.Error(), "licence refusee", 1)
	}
	if err := agentcore.SaveActivationState(appDir, rawLicense, envelope); err != nil {
		agentcore.Fail("ACTIVATION_WRITE_FAILED", err.Error(), 1)
	}
	agentcore.OK("ACTIVATED", "licence activee", map[string]any{
		"device_id": envelope.Payload.DeviceID,
	})
}
