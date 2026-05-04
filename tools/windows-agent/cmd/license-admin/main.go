package main

import (
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"flag"
	"fmt"
	"html/template"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"

	"radius-manager/windows-agent/internal/agentcore"
)

type pageData struct {
	Token       string
	ListenAddr  string
	Error       string
	Success     string
	LicenseKey  string
	LicenseJSON string
	OutputPath  string
	Form        licenseForm
}

type licenseForm struct {
	CustomerID string
	DeviceID   string
	NASType    string
	Edition    string
	ExpiresAt  string
	Features   string
	PrivateKey string
	OutputPath string
}

func main() {
	listen := flag.String("listen", "127.0.0.1:8780", "adresse locale de l interface")
	tokenFlag := flag.String("token", "", "token d acces impose par le lanceur local")
	noBrowser := flag.Bool("no-browser", false, "ne pas ouvrir le navigateur")
	flag.Parse()

	if !strings.HasPrefix(*listen, "127.0.0.1:") && !strings.HasPrefix(*listen, "localhost:") {
		log.Fatal("license-admin doit ecouter uniquement en local")
	}

	token := strings.TrimSpace(*tokenFlag)
	if token == "" {
		var err error
		token, err = newToken()
		if err != nil {
			log.Fatal(err)
		}
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/", handleIndex(token, *listen))
	mux.HandleFunc("/generate", handleGenerate(token, *listen))
	mux.HandleFunc("/keypair", handleKeypair(token, *listen))

	listener, err := net.Listen("tcp", *listen)
	if err != nil {
		log.Fatal(err)
	}

	url := "http://" + listener.Addr().String() + "/?token=" + token
	fmt.Println("Interface editeur licence: " + url)
	if !*noBrowser {
		_ = openBrowser(url)
	}

	server := &http.Server{
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
	}
	log.Fatal(server.Serve(listener))
}

func handleIndex(token string, listenAddr string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if !validToken(r, token) {
			http.Error(w, "token invalide", http.StatusForbidden)
			return
		}
		render(w, pageData{
			Token:      token,
			ListenAddr: listenAddr,
			Form: licenseForm{
				NASType:    "mikrotik",
				Edition:    "standard",
				ExpiresAt:  "never",
				PrivateKey: readDefaultPrivateKey(),
			},
		})
	}
}

func handleGenerate(token string, listenAddr string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if !validToken(r, token) {
			http.Error(w, "token invalide", http.StatusForbidden)
			return
		}
		if err := r.ParseForm(); err != nil {
			render(w, pageData{Token: token, ListenAddr: listenAddr, Error: err.Error()})
			return
		}

		form := licenseForm{
			CustomerID: strings.TrimSpace(r.FormValue("customer_id")),
			DeviceID:   strings.TrimSpace(r.FormValue("device_id")),
			NASType:    strings.TrimSpace(r.FormValue("nas_type")),
			Edition:    strings.TrimSpace(r.FormValue("edition")),
			ExpiresAt:  strings.TrimSpace(r.FormValue("expires_at")),
			Features:   strings.TrimSpace(r.FormValue("features")),
			PrivateKey: strings.TrimSpace(r.FormValue("private_key")),
			OutputPath: strings.TrimSpace(r.FormValue("output_path")),
		}
		if form.PrivateKey == "" {
			form.PrivateKey = readDefaultPrivateKey()
		}

		licenseKey, envelope, err := agentcore.SignLicense(agentcore.LicensePayload{
			CustomerID: form.CustomerID,
			DeviceID:   form.DeviceID,
			NASType:    form.NASType,
			Edition:    form.Edition,
			ExpiresAt:  form.ExpiresAt,
			Features:   splitFeatures(form.Features),
		}, form.PrivateKey)
		if err != nil {
			render(w, pageData{Token: token, ListenAddr: listenAddr, Error: err.Error(), Form: form})
			return
		}

		licenseJSON, err := json.MarshalIndent(envelope, "", "  ")
		if err != nil {
			render(w, pageData{Token: token, ListenAddr: listenAddr, Error: err.Error(), Form: form})
			return
		}
		if form.OutputPath != "" {
			if err := os.WriteFile(form.OutputPath, licenseJSON, 0600); err != nil {
				render(w, pageData{Token: token, ListenAddr: listenAddr, Error: err.Error(), Form: form})
				return
			}
		}

		message := "Licence signee generee."
		if form.OutputPath != "" {
			message += " Fichier ecrit: " + form.OutputPath
		}
		render(w, pageData{
			Token:       token,
			ListenAddr:  listenAddr,
			Success:     message,
			LicenseKey:  licenseKey,
			LicenseJSON: string(licenseJSON),
			OutputPath:  form.OutputPath,
			Form:        form,
		})
	}
}

func handleKeypair(token string, listenAddr string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if !validToken(r, token) {
			http.Error(w, "token invalide", http.StatusForbidden)
			return
		}
		privateKey, publicKey, err := agentcore.NewKeyPair()
		if err != nil {
			render(w, pageData{Token: token, ListenAddr: listenAddr, Error: err.Error()})
			return
		}
		payload, _ := json.MarshalIndent(map[string]string{
			"private_key": privateKey,
			"public_key":  publicKey,
		}, "", "  ")
		render(w, pageData{
			Token:       token,
			ListenAddr:  listenAddr,
			Success:     "Paire Ed25519 generee. Garder la cle privee hors client.",
			LicenseJSON: string(payload),
			Form: licenseForm{
				NASType:    "mikrotik",
				Edition:    "standard",
				ExpiresAt:  "never",
				PrivateKey: privateKey,
			},
		})
	}
}

func validToken(r *http.Request, token string) bool {
	if r.URL.Query().Get("token") == token {
		return true
	}
	return r.FormValue("token") == token
}

func render(w http.ResponseWriter, data pageData) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	if err := pageTemplate.Execute(w, data); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
	}
}

func splitFeatures(value string) []string {
	if strings.TrimSpace(value) == "" {
		return []string{}
	}
	raw := strings.Split(value, ",")
	features := make([]string, 0, len(raw))
	for _, item := range raw {
		item = strings.ToLower(strings.TrimSpace(item))
		if item != "" {
			features = append(features, item)
		}
	}
	return features
}

func newToken() (string, error) {
	bytes := make([]byte, 24)
	if _, err := rand.Read(bytes); err != nil {
		return "", err
	}
	return base64.RawURLEncoding.EncodeToString(bytes), nil
}

func readDefaultPrivateKey() string {
	value := strings.TrimSpace(os.Getenv("RM_LICENSE_PRIVATE_KEY"))
	if value != "" {
		return value
	}
	exePath, err := os.Executable()
	if err != nil {
		return ""
	}
	bytes, err := os.ReadFile(filepath.Join(filepath.Dir(exePath), "private_key.txt"))
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(bytes))
}

func openBrowser(url string) error {
	if runtime.GOOS == "windows" {
		return exec.Command("rundll32", "url.dll,FileProtocolHandler", url).Start()
	}
	if runtime.GOOS == "darwin" {
		return exec.Command("open", url).Start()
	}
	return exec.Command("xdg-open", url).Start()
}

var pageTemplate = template.Must(template.New("page").Parse(`<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>License Admin - Radius Manager</title>
  <style>
    :root { color-scheme: dark; font-family: Segoe UI, Arial, sans-serif; }
    body { margin:0; background:#07111f; color:#e5edf7; }
    main { width:min(980px, calc(100% - 32px)); margin:32px auto; }
    .card { background:#0d1b2d; border:1px solid #1d3557; border-radius:16px; padding:22px; box-shadow:0 20px 60px #0006; }
    h1 { margin:0 0 6px; font-size:1.45rem; }
    p { color:#9fb2c8; }
    label { display:block; margin:14px 0 6px; color:#bfd1e7; }
    input, textarea, select { width:100%; box-sizing:border-box; background:#07111f; color:#e5edf7; border:1px solid #2c4666; border-radius:10px; padding:10px 12px; }
    textarea { min-height:120px; font-family:Consolas, monospace; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .actions { display:flex; gap:12px; flex-wrap:wrap; margin-top:18px; }
    button, a.button { border:0; border-radius:999px; padding:10px 16px; background:#38bdf8; color:#06101d; font-weight:700; cursor:pointer; text-decoration:none; }
    a.button { background:#fbbf24; }
    .ok { border:1px solid #22c55e; background:#052e1a; color:#bbf7d0; padding:12px; border-radius:10px; }
    .err { border:1px solid #ef4444; background:#3a1014; color:#fecaca; padding:12px; border-radius:10px; }
    .warn { border:1px solid #f59e0b; background:#2a1b05; color:#fde68a; padding:12px; border-radius:10px; }
    pre { white-space:pre-wrap; word-break:break-word; background:#030812; border:1px solid #1d3557; border-radius:12px; padding:14px; }
    @media (max-width:720px) { .grid { grid-template-columns:1fr; } }
  </style>
</head>
<body>
<main>
  <div class="card">
    <h1>Générateur de licence - éditeur uniquement</h1>
    <p>Cette interface écoute localement sur <strong>{{.ListenAddr}}</strong>. Elle ne doit pas être livrée au client WAMP.</p>
    <div class="warn">La clé privée reste côté éditeur. Ne copiez jamais <code>license-admin.exe</code>, <code>license-generator.exe</code> ou la clé privée dans une installation client.</div>
    {{if .Error}}<p class="err">{{.Error}}</p>{{end}}
    {{if .Success}}<p class="ok">{{.Success}}</p>{{end}}
    <form method="post" action="/generate?token={{.Token}}">
      <input type="hidden" name="token" value="{{.Token}}">
      <div class="grid">
        <div>
          <label>Client</label>
          <input name="customer_id" value="{{.Form.CustomerID}}" placeholder="CLIENT-001" required>
        </div>
        <div>
          <label>Device ID</label>
          <input name="device_id" value="{{.Form.DeviceID}}" placeholder="MK-XXXX-XXXX-XXXX" required>
        </div>
        <div>
          <label>Type NAS</label>
          <select name="nas_type">
            <option value="mikrotik" {{if eq .Form.NASType "mikrotik"}}selected{{end}}>mikrotik</option>
            <option value="radius" {{if eq .Form.NASType "radius"}}selected{{end}}>radius</option>
            <option value="opnsense" {{if eq .Form.NASType "opnsense"}}selected{{end}}>opnsense</option>
          </select>
        </div>
        <div>
          <label>Édition</label>
          <input name="edition" value="{{.Form.Edition}}" placeholder="standard" required>
        </div>
        <div>
          <label>Expiration</label>
          <input name="expires_at" value="{{.Form.ExpiresAt}}" placeholder="never ou yyyy-mm-dd" required>
        </div>
        <div>
          <label>Features</label>
          <input name="features" value="{{.Form.Features}}" placeholder="vouchers,recharge,reports">
        </div>
      </div>
      <label>Clé privée éditeur Ed25519 base64</label>
      <textarea name="private_key" placeholder="RM_LICENSE_PRIVATE_KEY">{{.Form.PrivateKey}}</textarea>
      <label>Fichier de sortie optionnel</label>
      <input name="output_path" value="{{.Form.OutputPath}}" placeholder="C:\licences\client-license.json">
      <div class="actions">
        <button type="submit">Générer la licence</button>
        <a class="button" href="/keypair?token={{.Token}}">Générer une paire de clés</a>
      </div>
    </form>
    {{if .LicenseKey}}
      <h2>Clé d'activation</h2>
      <pre>{{.LicenseKey}}</pre>
    {{end}}
    {{if .LicenseJSON}}
      <h2>JSON</h2>
      <pre>{{.LicenseJSON}}</pre>
    {{end}}
  </div>
</main>
</body>
</html>`))
