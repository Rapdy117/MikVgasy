document.addEventListener("DOMContentLoaded", function () {

    const form = document.getElementById("radiusForm");
    const testBtn = document.getElementById("testRadius");
    const result = document.getElementById("testResult");
    const loadButton = document.getElementById("loadBtn");

    // =========================
    // SAVE
    // =========================
    if (form) {
        form.addEventListener("submit", function (e) {
            e.preventDefault();

            fetch('../config/radius.php', {
                method: 'POST',
                body: new FormData(form)
            })
            .then(res => res.json())
            .then(data => {
                console.log("SAVE OK:", data);
            })
            .catch(err => console.error("SAVE ERROR:", err));
        });
    }

    // =========================
    // LOAD BUTTON
    // =========================
    if (loadButton) {
        loadButton.addEventListener('click', loadConfig);
    }

    // =========================
    // AUTO LOAD
    // =========================
    loadConfig();

    // =========================
    // TEST
    // =========================
    if (testBtn) {
        testBtn.addEventListener("click", function () {

            const user = document.getElementById("testUser").value.trim();
            const pass = document.getElementById("testPass").value.trim();

            if (!user || !pass) {
                result.innerHTML = "❌ User/Password requis\n";
                return;
            }

            result.innerHTML = "Testing...\n";

            console.log("USER:", user);
            console.log("PASS:", pass);

            const formData = new FormData(form);
            formData.append("user", user);
            formData.append("pass", pass);

            fetch('../api/test_radius.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {

                result.innerHTML += data.log + "\n";

                result.innerHTML += data.success
                    ? "✔ AUTH SUCCESS\n"
                    : "❌ AUTH FAILED\n";

            })
            .catch(err => {
                result.innerHTML += "❌ ERROR\n";
                console.error(err);
            });
        });
    }

    // =========================
    // LOAD FUNCTION
    // =========================
    function loadConfig() {
        fetch('../config/radius.php')
            .then(res => res.json())
            .then(data => {
                document.getElementById("testUser").value = data.test_user || '';
                document.getElementById("testPass").value = data.test_pass || '';
                document.querySelector('[name="host"]').value = data.host || '';
                document.querySelector('[name="auth_port"]').value = data.auth_port || 1812;
                document.querySelector('[name="acct_port"]').value = data.acct_port || 1813;
                document.querySelector('[name="secret"]').value = data.secret || '';
                document.querySelector('[name="timeout"]').value = data.timeout || 3;

            })
            .catch(err => console.error("LOAD ERROR:", err));
    }

});