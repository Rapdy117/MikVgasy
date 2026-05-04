package main

import (
	"flag"
	"os"

	"radius-manager/windows-agent/internal/agentcore"
)

func main() {
	if len(os.Args) < 2 {
		agentcore.Fail("COMMAND_REQUIRED", "commande requise", 2)
	}
	switch os.Args[1] {
	case "activate":
		activate(os.Args[2:])
	case "status":
		status(os.Args[2:])
	default:
		agentcore.Fail("COMMAND_UNKNOWN", "commande inconnue", 2)
	}
}

func activate(args []string) {
	flags := flag.NewFlagSet("activate", flag.ContinueOnError)
	licenseInput := flags.String("license", "", "fichier ou cle licence")
	appDirRaw := flags.String("app-dir", "", "racine application")
	deviceID := flags.String("device-id", "", "device id attendu")
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
	if err := agentcore.VerifyLicense(envelope, publicKey, *deviceID); err != nil {
		agentcore.Fail(err.Error(), "licence refusee", 1)
	}
	if err := agentcore.SaveActivationState(appDir, rawLicense, envelope); err != nil {
		agentcore.Fail("ACTIVATION_WRITE_FAILED", err.Error(), 1)
	}
	agentcore.OK("ACTIVATED", "licence activee", map[string]any{
		"device_id":  envelope.Payload.DeviceID,
		"customer":   envelope.Payload.CustomerID,
		"edition":    envelope.Payload.Edition,
		"expires_at": envelope.Payload.ExpiresAt,
	})
}

func status(args []string) {
	flags := flag.NewFlagSet("status", flag.ContinueOnError)
	appDirRaw := flags.String("app-dir", "", "racine application")
	if err := flags.Parse(args); err != nil {
		agentcore.Fail("ARGUMENTS_INVALID", err.Error(), 2)
	}
	appDir, err := agentcore.CleanAppDir(*appDirRaw)
	if err != nil {
		agentcore.Fail("APP_DIR_INVALID", err.Error(), 2)
	}
	state, err := agentcore.LoadActivationState(appDir)
	if err != nil {
		agentcore.Fail("LICENSE_NOT_ACTIVATED", "aucune activation locale", 1)
	}
	publicKey, err := agentcore.LoadPublicKey(appDir)
	if err != nil {
		agentcore.Fail(err.Error(), "cle publique indisponible ou invalide", 2)
	}
	if err := agentcore.VerifyLicense(state.License, publicKey, ""); err != nil {
		agentcore.Fail(err.Error(), "licence refusee", 1)
	}
	agentcore.OK("LICENSE_ACTIVE", "licence active", map[string]any{
		"device_id":    state.License.Payload.DeviceID,
		"customer":     state.License.Payload.CustomerID,
		"edition":      state.License.Payload.Edition,
		"expires_at":   state.License.Payload.ExpiresAt,
		"activated_at": state.ActivatedAt,
	})
}
