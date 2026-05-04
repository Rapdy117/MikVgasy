package main

import (
	"encoding/json"
	"flag"
	"os"
	"strings"

	"radius-manager/windows-agent/internal/agentcore"
)

func main() {
	if len(os.Args) < 2 {
		agentcore.Fail("COMMAND_REQUIRED", "commande requise", 2)
	}
	switch os.Args[1] {
	case "generate":
		generate(os.Args[2:])
	case "new-keypair":
		privateKey, publicKey, err := agentcore.NewKeyPair()
		if err != nil {
			agentcore.Fail("KEYPAIR_ERROR", err.Error(), 1)
		}
		agentcore.OK("KEYPAIR_GENERATED", "paire Ed25519 generee", map[string]any{
			"private_key": privateKey,
			"public_key":  publicKey,
		})
	default:
		agentcore.Fail("COMMAND_UNKNOWN", "commande inconnue", 2)
	}
}

func generate(args []string) {
	flags := flag.NewFlagSet("generate", flag.ContinueOnError)
	customer := flags.String("customer", "", "identifiant client")
	deviceID := flags.String("device-id", "", "device id")
	nasType := flags.String("nas-type", "", "type NAS")
	edition := flags.String("edition", "standard", "edition")
	expires := flags.String("expires", "never", "expiration")
	features := flags.String("features", "", "features separees par virgule")
	out := flags.String("out", "", "fichier de sortie")
	privateKey := flags.String("private-key", "", "cle privee Ed25519 base64")
	if err := flags.Parse(args); err != nil {
		agentcore.Fail("ARGUMENTS_INVALID", err.Error(), 2)
	}
	privateKeyValue := strings.TrimSpace(*privateKey)
	if privateKeyValue == "" {
		privateKeyValue = strings.TrimSpace(os.Getenv("RM_LICENSE_PRIVATE_KEY"))
	}
	if privateKeyValue == "" {
		agentcore.Fail("PRIVATE_KEY_MISSING", "RM_LICENSE_PRIVATE_KEY requis", 2)
	}
	payload := agentcore.LicensePayload{
		CustomerID: *customer,
		DeviceID:   *deviceID,
		NASType:    *nasType,
		Edition:    *edition,
		ExpiresAt:  *expires,
		Features:   splitFeatures(*features),
	}
	licenseKey, envelope, err := agentcore.SignLicense(payload, privateKeyValue)
	if err != nil {
		agentcore.Fail("LICENSE_GENERATE_FAILED", err.Error(), 1)
	}
	if *out != "" {
		bytes, err := json.MarshalIndent(envelope, "", "  ")
		if err != nil {
			agentcore.Fail("LICENSE_WRITE_FAILED", err.Error(), 1)
		}
		if err := os.WriteFile(*out, bytes, 0600); err != nil {
			agentcore.Fail("LICENSE_WRITE_FAILED", err.Error(), 1)
		}
	}
	agentcore.OK("LICENSE_GENERATED", "licence signee generee", map[string]any{
		"license_key": licenseKey,
		"license":     envelope,
	})
}

func splitFeatures(value string) []string {
	if strings.TrimSpace(value) == "" {
		return []string{}
	}
	raw := strings.Split(value, ",")
	features := make([]string, 0, len(raw))
	for _, item := range raw {
		item = strings.TrimSpace(item)
		if item != "" {
			features = append(features, item)
		}
	}
	return features
}
