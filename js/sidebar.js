document.addEventListener("DOMContentLoaded", function () {

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