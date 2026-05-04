package agentcore

import (
	"errors"
	"os"
	"path/filepath"
)

const ActivationStateRelativePath = "config/license/activation.json"
const IntegrityManifestRelativePath = "config/license/integrity.json"
const PublicKeyRelativePath = "config/license/public_key.txt"

func CleanAppDir(appDir string) (string, error) {
	if appDir == "" {
		return "", errors.New("app-dir requis")
	}
	absolute, err := filepath.Abs(appDir)
	if err != nil {
		return "", err
	}
	info, err := os.Stat(absolute)
	if err != nil {
		return "", err
	}
	if !info.IsDir() {
		return "", errors.New("app-dir doit pointer vers un dossier")
	}
	return absolute, nil
}

func ActivationStatePath(appDir string) string {
	return filepath.Join(appDir, filepath.FromSlash(ActivationStateRelativePath))
}

func IntegrityManifestPath(appDir string) string {
	return filepath.Join(appDir, filepath.FromSlash(IntegrityManifestRelativePath))
}

func PublicKeyPath(appDir string) string {
	return filepath.Join(appDir, filepath.FromSlash(PublicKeyRelativePath))
}
