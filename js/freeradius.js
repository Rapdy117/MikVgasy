document.addEventListener("DOMContentLoaded", function () {

    const configForm = document.getElementById("radiusConfigForm");
    const userTestForm = document.getElementById("radiusUserTestForm");
    const serverTestBtn = document.getElementById("testRadiusServer");
    const userTestBtn = document.getElementById("testRadiusUser");
    const result = document.getElementById("testResult");
    const loadButton = document.getElementById("loadBtn");
    const testUser = document.getElementById("testUser");
    const testPass = document.getElementById("testPass");
    const statusHost = document.getElementById("radiusStatusHost");
    const statusAuthPort = document.getElementById("radiusStatusAuthPort");
    const statusAcctPort = document.getElementById("radiusStatusAcctPort");
    const statusTimeout = document.getElementById("radiusStatusTimeout");
    const statusSecret = document.getElementById("radiusStatusSecret");

    function renderResult(title, data, successLabel, failureLabel) {
        if (!result) {
            return;
        }

        let finalLabel = data.success ? successLabel : failureLabel;

        if (data.result_code === "no_reply") {
            finalLabel = "⚠ AUCUNE REPONSE DU SERVEUR RADIUS";
        } else if (data.result_code === "access_reject") {
            finalLabel = "❌ ACCES REJETE PAR LE SERVEUR RADIUS";
        } else if (data.result_code === "unknown_error") {
            finalLabel = "❌ ERREUR OU REPONSE RADIUS INCONNUE";
        }

        result.textContent = `${title}\n\n${data.log || ''}\n\n${finalLabel}\n`;
    }

    function buildServerFormData() {
        return configForm ? new FormData(configForm) : new FormData();
    }

    function getConfigValue(name, fallback) {
        if (!configForm) {
            return fallback;
        }

        const field = configForm.querySelector(`[name="${name}"]`);
        return field ? field.value : fallback;
    }

    function refreshStatusBox() {
        if (!configForm) {
            return;
        }

        const host = getConfigValue("host", "").trim();
        const authPort = getConfigValue("auth_port", "1812").trim();
        const acctPort = getConfigValue("acct_port", "1813").trim();
        const timeout = getConfigValue("timeout", "3").trim();
        const secret = getConfigValue("secret", "").trim();

        if (statusHost) {
            statusHost.textContent = host !== "" ? host : "-";
        }
        if (statusAuthPort) {
            statusAuthPort.textContent = authPort !== "" ? authPort : "1812";
        }
        if (statusAcctPort) {
            statusAcctPort.textContent = acctPort !== "" ? acctPort : "1813";
        }
        if (statusTimeout) {
            statusTimeout.textContent = `${timeout !== "" ? timeout : "3"} s`;
        }
        if (statusSecret) {
            statusSecret.textContent = secret !== "" ? "Défini" : "Non défini";
        }
    }

    if (configForm) {
        configForm.addEventListener("submit", function (e) {
            e.preventDefault();

            fetch('../config/radius.php', {
                method: 'POST',
                body: new FormData(configForm)
            })
            .then(res => res.json())
            .then(data => {
                console.log("SAVE OK:", data);
                if (result) {
                    result.textContent = "Configuration FreeRADIUS sauvegardée.\n";
                }
                refreshStatusBox();
            })
            .catch(err => {
                console.error("SAVE ERROR:", err);
                if (result) {
                    result.textContent = "Erreur lors de la sauvegarde de la configuration.\n";
                }
            });
        });
    }

    if (loadButton) {
        loadButton.addEventListener('click', loadConfig);
    }

    loadConfig();

    if (serverTestBtn) {
        serverTestBtn.addEventListener("click", function () {
            if (result) {
                result.textContent = "Test du serveur RADIUS en cours...\n";
            }

            const formData = buildServerFormData();
            formData.set("test_mode", "server");

            fetch('../api/test_radius.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                renderResult(
                    "=== TEST SERVEUR RADIUS ===",
                    data,
                    "✔ SERVEUR RADIUS DISPONIBLE",
                    "❌ TEST SERVEUR RADIUS ÉCHOUÉ"
                );
            })
            .catch(err => {
                if (result) {
                    result.textContent = "❌ ERREUR TEST SERVEUR RADIUS\n";
                }
                console.error(err);
            });
        });
    }

    if (userTestBtn) {
        userTestBtn.addEventListener("click", function () {
            const user = testUser ? testUser.value.trim() : '';
            const pass = testPass ? testPass.value.trim() : '';

            if (!user || !pass) {
                if (result) {
                    result.textContent = "❌ Utilisateur et mot de passe requis pour le test d'authentification.\n";
                }
                return;
            }

            if (result) {
                result.textContent = "Test d'authentification RADIUS en cours...\n";
            }

            const formData = buildServerFormData();
            formData.set("test_mode", "user_auth");
            formData.set("user", user);
            formData.set("pass", pass);

            fetch('../api/test_radius.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                renderResult(
                    "=== TEST UTILISATEUR RADIUS ===",
                    data,
                    "✔ AUTHENTIFICATION RADIUS RÉUSSIE",
                    "❌ AUTHENTIFICATION RADIUS ÉCHOUÉE"
                );
            })
            .catch(err => {
                if (result) {
                    result.textContent = "❌ ERREUR TEST UTILISATEUR RADIUS\n";
                }
                console.error(err);
            });
        });
    }

    function loadConfig() {
        fetch('../config/radius.php')
            .then(res => res.json())
            .then(data => {
                if (configForm) {
                    configForm.querySelector('[name="host"]').value = data.host || '';
                    configForm.querySelector('[name="auth_port"]').value = data.auth_port || 1812;
                    configForm.querySelector('[name="acct_port"]').value = data.acct_port || 1813;
                    configForm.querySelector('[name="secret"]').value = data.secret || '';
                    configForm.querySelector('[name="timeout"]').value = data.timeout || 3;
                }

                if (testUser) {
                    testUser.value = data.test_user || '';
                }

                if (testPass) {
                    testPass.value = data.test_pass || '';
                }

                refreshStatusBox();
            })
            .catch(err => console.error("LOAD ERROR:", err));
    }

    if (configForm) {
        configForm.addEventListener("input", refreshStatusBox);
    }

});
