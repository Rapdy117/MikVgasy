document.addEventListener("DOMContentLoaded", function () {
    const routerInfo = document.getElementById("routerInfo");
    const navbarDeviceIcon = document.getElementById("navbarDeviceIcon");
    const navbarDeviceSwitchBtn = document.getElementById("navbarDeviceSwitchBtn");
    const navbarDeviceSwitchResults = document.getElementById("navbarDeviceSwitchResults");
    const navbarSearchForm = document.getElementById("navbarSearchForm");
    const navbarSearchInput = document.getElementById("navbarSearchInput");
    const navbarSearchResults = document.getElementById("navbarSearchResults");
    const navbarSearchIndexNode = document.getElementById("navbarSearchIndex");

    function normalizeDeviceStatus(statusValue) {
        const normalized = String(statusValue || "offline").toLowerCase();
        if (normalized === "active" || normalized === "connected" || normalized === "offline") {
            return normalized;
        }
        return "offline";
    }

    function applyNavbarDeviceStatus() {
        if (!routerInfo || !navbarDeviceIcon) {
            return;
        }

        let statuses = {};
        try {
            statuses = JSON.parse(window.localStorage.getItem("networkDeviceStatuses") || "{}") || {};
        } catch (error) {
            statuses = {};
        }

        const deviceId = routerInfo.dataset.deviceId || "";
        const status = normalizeDeviceStatus(statuses[deviceId] || "offline");

        navbarDeviceIcon.classList.remove(
            "device-status-offline",
            "device-status-connected",
            "device-status-active"
        );
        navbarDeviceIcon.classList.add(`device-status-${status}`);
    }

    applyNavbarDeviceStatus();

    function buildPageIndex() {
        if (!navbarSearchIndexNode) {
            return [];
        }

        let rawIndex = [];
        try {
            rawIndex = JSON.parse(navbarSearchIndexNode.textContent || "[]");
        } catch (error) {
            rawIndex = [];
        }

        return rawIndex
            .map(item => {
                const label = String(item.label || "").trim();
                const href = String(item.href || "").trim();
                const breadcrumb = String(item.breadcrumb || label).trim();
                const keywords = Array.isArray(item.keywords) ? item.keywords : [];
                const basename = href.split("/").pop() || href;
                const searchText = [
                    label,
                    breadcrumb,
                    basename.replace(".php", "").replaceAll("_", " "),
                    ...keywords
                ]
                    .join(" ")
                    .toLowerCase();

                return {
                    label,
                    href,
                    breadcrumb,
                    text: searchText
                };
            })
            .filter(item => item.label !== "" && item.href !== "");
    }

    const pageIndex = buildPageIndex();

    function hideNavbarResults() {
        if (!navbarSearchResults) {
            return;
        }
        navbarSearchResults.hidden = true;
        navbarSearchResults.innerHTML = "";
    }

    function renderNavbarResults(query) {
        if (!navbarSearchResults) {
            return;
        }

        const normalized = String(query || "").trim().toLowerCase();
        if (normalized === "") {
            hideNavbarResults();
            return;
        }

        const matches = pageIndex.filter(item => item.text.includes(normalized)).slice(0, 8);
        if (matches.length === 0) {
            navbarSearchResults.innerHTML = '<button type="button" class="navbar-search-result-item text-white-50" disabled>Aucun resultat</button>';
            navbarSearchResults.hidden = false;
            return;
        }

        navbarSearchResults.innerHTML = matches.map(item => `
            <button type="button" class="navbar-search-result-item" data-href="${item.href}">
                <span class="navbar-search-result-title">${item.label}</span>
                <span class="navbar-search-result-meta">${item.breadcrumb}</span>
            </button>
        `).join("");
        navbarSearchResults.hidden = false;
    }

    function openNavbarResult(query) {
        const normalized = String(query || "").trim().toLowerCase();
        if (normalized === "") {
            return;
        }

        const exact = pageIndex.find(item => item.text === normalized);
        const first = exact || pageIndex.find(item => item.text.includes(normalized));
        if (first) {
            window.location.href = first.href;
        }
    }

    if (navbarSearchForm && navbarSearchInput && navbarSearchResults) {
        navbarSearchResults.hidden = true;

        navbarSearchInput.addEventListener("input", function () {
            renderNavbarResults(this.value);
        });

        navbarSearchInput.addEventListener("focus", function () {
            if (this.value.trim() !== "") {
                renderNavbarResults(this.value);
            }
        });

        navbarSearchForm.addEventListener("submit", function (event) {
            event.preventDefault();
            openNavbarResult(navbarSearchInput.value);
        });

        navbarSearchResults.addEventListener("click", function (event) {
            const target = event.target.closest(".navbar-search-result-item[data-href]");
            if (!target) {
                return;
            }
            window.location.href = target.dataset.href || "";
        });

        document.addEventListener("click", function (event) {
            if (!navbarSearchForm.contains(event.target)) {
                hideNavbarResults();
            }
        });
    }

    function hideDeviceSwitchResults() {
        if (!navbarDeviceSwitchResults || !navbarDeviceSwitchBtn) {
            return;
        }

        navbarDeviceSwitchResults.hidden = true;
        navbarDeviceSwitchResults.innerHTML = "";
        navbarDeviceSwitchBtn.setAttribute("aria-expanded", "false");
    }

    async function loadNavbarDevices() {
        const response = await fetch("../api/network_devices_api.php", {
            method: "GET",
            credentials: "same-origin",
            cache: "no-store"
        });

        const data = await response.json();
        if (!Array.isArray(data.devices)) {
            throw new Error(data.message || "Devices introuvables");
        }

        const activeDeviceId = String(data.active_device_id || "");
        return data.devices
            .map(device => ({
                id: String(device.id || ""),
                name: String(device.name || device.ip || device.host || "Device"),
                host: String(device.ip || device.host || ""),
                isActive: String(device.id || "") === activeDeviceId
            }))
            .filter(device => device.id !== "");
    }

    function renderDeviceSwitchResults(devices) {
        if (!navbarDeviceSwitchResults || !navbarDeviceSwitchBtn) {
            return;
        }

        if (!Array.isArray(devices) || devices.length === 0) {
            navbarDeviceSwitchResults.innerHTML = '<button type="button" class="navbar-device-switch-item text-white-50" disabled>Aucun NAS disponible</button>';
            navbarDeviceSwitchResults.hidden = false;
            navbarDeviceSwitchBtn.setAttribute("aria-expanded", "true");
            return;
        }

        navbarDeviceSwitchResults.innerHTML = devices.map(device => `
            <button type="button" class="navbar-device-switch-item${device.isActive ? ' is-active' : ''}" data-device-id="${device.id}">
                <span class="navbar-device-switch-title">${device.name} : ${device.host || '-'}</span>
            </button>
        `).join("");
        navbarDeviceSwitchResults.hidden = false;
        navbarDeviceSwitchBtn.setAttribute("aria-expanded", "true");
    }

    async function setActiveNavbarDevice(deviceId) {
        const formData = new FormData();
        formData.set("action", "set_active");
        formData.set("id", deviceId);

        const response = await fetch("../api/network_devices_api.php", {
            method: "POST",
            credentials: "same-origin",
            body: formData
        });
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || "Activation impossible");
        }

        window.location.reload();
    }

    if (navbarDeviceSwitchBtn && navbarDeviceSwitchResults) {
        navbarDeviceSwitchBtn.addEventListener("click", async function (event) {
            event.preventDefault();
            event.stopPropagation();

            if (!navbarDeviceSwitchResults.hidden) {
                hideDeviceSwitchResults();
                return;
            }

            navbarDeviceSwitchResults.innerHTML = '<button type="button" class="navbar-device-switch-item text-white-50" disabled>Chargement...</button>';
            navbarDeviceSwitchResults.hidden = false;
            navbarDeviceSwitchBtn.setAttribute("aria-expanded", "true");

            try {
                const devices = await loadNavbarDevices();
                renderDeviceSwitchResults(devices);
            } catch (error) {
                navbarDeviceSwitchResults.innerHTML = '<button type="button" class="navbar-device-switch-item text-white-50" disabled>Chargement impossible</button>';
            }
        });

        navbarDeviceSwitchResults.addEventListener("click", async function (event) {
            const target = event.target.closest(".navbar-device-switch-item[data-device-id]");
            if (!target) {
                return;
            }

            const deviceId = target.dataset.deviceId || "";
            if (deviceId === "") {
                return;
            }

            target.disabled = true;
            target.querySelector(".navbar-device-switch-meta")?.remove();
            target.insertAdjacentHTML("beforeend", '<span class="navbar-device-switch-meta">Activation...</span>');

            try {
                await setActiveNavbarDevice(deviceId);
            } catch (error) {
                target.disabled = false;
                AppToast.flash(error.message || 'Activation impossible', 'danger');
            }
        });

        document.addEventListener("click", function (event) {
            const switchWrapper = navbarDeviceSwitchBtn.closest(".navbar-text");
            if (switchWrapper && !switchWrapper.contains(event.target)) {
                hideDeviceSwitchResults();
            }
        });
    }

    document.querySelectorAll(".dropdown-btn").forEach(btn => {

        btn.onclick = function (e) {

            e.preventDefault();
            e.stopPropagation();

            // 🔥 IMPORTANT : on cible le bon submenu
            const parent = this.closest(".menu-item");
            const submenu = parent.querySelector(":scope > .submenu-group");

            if (!submenu) return;

            this.classList.toggle("active");
            submenu.classList.toggle("open");

        };

    });

});

// =========================
// EXPLICATIONS DE PAGE (blocs .page-flow-explanation)
// Bouton discret #navbarFlowExplanationToggle — persistance localStorage
// =========================
document.addEventListener("DOMContentLoaded", function () {
    const STORAGE_KEY = "pageFlowExplanationsHidden";
    const btn = document.getElementById("navbarFlowExplanationToggle");
    const panels = () => document.querySelectorAll(".page-flow-explanation");

    function apply() {
        const hidden = window.localStorage.getItem(STORAGE_KEY) === "1";
        panels().forEach((el) => {
            el.classList.toggle("d-none", hidden);
        });
        if (!btn) {
            return;
        }
        btn.setAttribute("aria-pressed", hidden ? "true" : "false");
        btn.title = hidden
            ? "Afficher les explications de page"
            : "Masquer les explications de page";
        const icon = btn.querySelector("i");
        if (icon) {
            icon.className = hidden ? "fa fa-eye" : "fa fa-eye-slash";
        }
    }

    if (!btn) {
        return;
    }

    if (panels().length === 0) {
        btn.classList.add("d-none");
        return;
    }

    apply();
    btn.addEventListener("click", function () {
        const cur = window.localStorage.getItem(STORAGE_KEY) === "1";
        window.localStorage.setItem(STORAGE_KEY, cur ? "0" : "1");
        apply();
    });
});

// =========================
// AUTO OPEN MENU ACTIVE
// =========================
document.querySelectorAll(".list-group-item.active").forEach(activeItem => {

    let parent = activeItem.closest(".submenu-group");

    while (parent) {

        parent.classList.add("open");

        const parentMenu = parent.closest(".menu-item");

        if (parentMenu) {
            const btn = parentMenu.querySelector(":scope > .dropdown-btn");
            if (btn) btn.classList.add("active");
        }

        parent = parentMenu ? parentMenu.closest(".submenu-group") : null;
    }

});
