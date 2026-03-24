let isNew = false;
document.addEventListener("DOMContentLoaded", function () {

    const form = document.getElementById("networkDeviceForm");
    const testBtn = document.getElementById("testDevice");
    const loadButton = document.getElementById("loadDeviceBtn");
    const status = document.getElementById("testStatus");
    const newBtn = document.getElementById("newDeviceBtn");

    if (newBtn && form) {
        newBtn.addEventListener("click", () => {

            form.reset();

            // ⚠️ CRITIQUE
            form.querySelector('[name="id"]').value = '';

            console.log("NEW DEVICE MODE");
        });
    }

    // =========================
    // SAVE DEVICE
    // =========================
    if (form) {
        form.addEventListener("submit", function (e) {
            e.preventDefault();

            console.log("SUBMIT INTERCEPTED");

            // 🔥 FORCER CRÉATION SI ID VIDE OU MODE NEW
            const formData = new FormData(form);

            console.log("ID VALUE:", form.querySelector('[name="id"]').value);
            console.log("SAVE DATA:", [...formData.entries()]);

            fetch('../api/network_devices_api.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.text())
            .then(data => {

                console.log("RAW RESPONSE:", data);

                let json;

                try {
                    json = JSON.parse(data);
                } catch (e) {
                    console.error("INVALID JSON:", data);
                    return;
                }

                if (!json.success) {
                    console.error("SAVE FAILED:", json.message);
                    alert("Erreur: " + (json.message || "Unknown error"));
                    return;
                }

                console.log("SAVE OK:", json);
                loadDevices();
            })
            .catch(err => {
                console.error("SAVE ERROR:", err);
            });
        });
    }

    // =========================
    // LOAD BUTTON
    // =========================
    if (loadButton) {
        loadButton.addEventListener('click', loadDevices);
    }

    // =========================
    // AUTO LOAD
    // =========================
    loadDevices();

    // =========================
    // TEST API
    // =========================
    if (testBtn) {
        testBtn.addEventListener("click", function () {

            status.innerHTML = "Testing...\n";

            const formData = new FormData(form);

            fetch('../api/test_opnsense.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {

                status.innerHTML = ""; // reset

                status.innerHTML += data.log + "\n";

                status.innerHTML += data.success
                    ? "✔ SUCCESS\n"
                    : "❌ FAILED\n";

            })
            .catch(err => {
                status.innerHTML = "❌ ERROR\n";
                console.error(err);
            });
        });
    }

    // =========================
    // LOAD DEVICES
    // =========================
    function loadDevices() {
        fetch('../api/network_devices_api.php?t=' + Date.now())
            .then(res => res.json())
            .then(data => {

                if (!data.devices) return;

                renderTable(data.devices);

                //if (data.devices.length > 0) {
                //    fillForm(data.devices[0]);
                //}

            })
            .catch(err => console.error("LOAD ERROR:", err));
    }

    // =========================
    // FILL FORM
    // =========================
    function fillForm(device) {

        document.querySelector('[name="id"]').value = device.id || '';

        document.querySelector('[name="device_name"]').value = device.name || '';
        document.querySelector('[name="host"]').value = device.host || '';
        document.querySelector('[name="api_key"]').value = device.api_key || '';
        document.querySelector('[name="api_secret"]').value = device.api_secret || '';

        document.querySelector('[name="verify_ssl"]').value =
            device.verify_ssl ? "true" : "false";
    }
     // =========================
    // STATUS CHECK
    // =========================   
    function checkDeviceStatus(device, callback) {

                    const formData = new FormData();
                    formData.append('host', device.host);
                    formData.append('api_key', device.api_key);
                    formData.append('api_secret', device.api_secret);
                    formData.append('verify_ssl', device.verify_ssl ? 'true' : 'false');
                    formData.append('status_only', '1');

                    fetch('../api/test_opnsense.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => callback(data.success))
                    .catch(() => callback(false));
                }
    // =========================
    // RENDER TABLE
    // =========================
    function renderTable(devices) {

        const tbody = document.getElementById("deviceTableBody");
        tbody.innerHTML = "";

        devices.forEach(device => {

            const tr = document.createElement("tr");

            tr.innerHTML = `
                <td>${device.name}</td>
                <td>${device.host}</td>
                <td>
                    <span class="status-badge checking">Checking...</span>
                </td>
                
                <td>
                    <button class="btn btn-select btn-sm select-btn">
                        <i class="fa fa-pen"></i>
                    </button>

                    <button class="btn btn-delete btn-sm delete-btn">
                        <i class="fa fa-trash"></i>
                    </button>
                </td>
            `;
            const statusEl = tr.querySelector('.status-badge');

            checkDeviceStatus(device, (isOnline) => {

                statusEl.classList.remove('checking');

                if (isOnline) {
                    statusEl.classList.add('online');
                    statusEl.textContent = 'Online';
                } else {
                    statusEl.classList.add('offline');
                    statusEl.textContent = 'Offline';
                }

            });

            // SELECT
            tr.querySelector(".select-btn").addEventListener("click", () => {
                fillForm(device);
            });

            // DELETE (avec ID si dispo)
            tr.querySelector(".delete-btn").addEventListener("click", () => {
                deleteDevice(device.id || device.name);
            });

            tbody.appendChild(tr);
        });
    }

    // =========================
    // DELETE DEVICE
    // =========================
    function deleteDevice(identifier) {

        if (!confirm("Delete this device ?")) return;

        fetch('../api/network_devices_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete&id=${encodeURIComponent(identifier)}`
        })
        .then(res => res.json())
        .then(() => loadDevices())
        .catch(err => console.error(err));
    }

});