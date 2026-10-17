/* ==========================================================
   AppToast — Système de notification unifié
   Usage : AppToast.flash(message, type, duration?)
   Types : 'success' | 'danger' | 'warning' | 'info'
   ========================================================== */
(function () {
    'use strict';

    const ICONS = {
        success: '<i class="fa fa-check-circle" aria-hidden="true"></i>',
        danger:  '<i class="fa fa-times-circle" aria-hidden="true"></i>',
        warning: '<i class="fa fa-exclamation-triangle" aria-hidden="true"></i>',
        info:    '<i class="fa fa-info-circle" aria-hidden="true"></i>',
    };

    const VALID_TYPES   = ['success', 'danger', 'warning', 'info'];
    const DEFAULT_DELAY = 3600;  /* ms avant disparition auto */

    let _container = null;

    /* -- Conteneur singleton ---------------------------------- */
    function getContainer() {
        if (_container && document.body.contains(_container)) {
            return _container;
        }
        _container = document.getElementById('appToastContainer');
        if (!_container) {
            _container = document.createElement('div');
            _container.id        = 'appToastContainer';
            _container.className = 'app-toast-container';
            _container.setAttribute('aria-live', 'polite');
            _container.setAttribute('aria-atomic', 'false');
            document.body.appendChild(_container);
        }
        return _container;
    }

    /* -- Disparition animée ----------------------------------- */
    function dismiss(toast) {
        if (!toast || toast._dismissed) { return; }
        toast._dismissed = true;
        clearTimeout(toast._autoTimer);
        toast.classList.add('app-toast--exiting');
        /* Suppression après la fin de l'animation (ou timeout de sécurité) */
        const remove = () => { if (toast.parentNode) { toast.remove(); } };
        toast.addEventListener('animationend', remove, { once: true });
        setTimeout(remove, 400);
    }

    /* -- Afficher un toast ------------------------------------ */
    function flash(message, type, duration) {
        /* Sécurisations */
        const safeType    = VALID_TYPES.includes(type) ? type : 'info';
        const safeMsg     = String(message ?? '').trim() || 'Notification';
        const safeDelay   = (typeof duration === 'number' && duration > 0) ? duration : DEFAULT_DELAY;

        const container = getContainer();

        const toast = document.createElement('div');
        toast.className = `app-toast app-toast--${safeType}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-atomic', 'true');

        /* Le contenu est en texte pour éviter les injections XSS */
        const inner = document.createElement('div');
        inner.className = 'app-toast__inner';

        const iconSpan = document.createElement('span');
        iconSpan.className = 'app-toast__icon';
        iconSpan.innerHTML = ICONS[safeType];

        const bodySpan = document.createElement('span');
        bodySpan.className   = 'app-toast__body';
        bodySpan.textContent = safeMsg;

        const closeBtn = document.createElement('button');
        closeBtn.className   = 'app-toast__close';
        closeBtn.type        = 'button';
        closeBtn.setAttribute('aria-label', 'Fermer');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', () => dismiss(toast));

        inner.appendChild(iconSpan);
        inner.appendChild(bodySpan);
        inner.appendChild(closeBtn);
        toast.appendChild(inner);
        container.appendChild(toast);

        toast._autoTimer = setTimeout(() => dismiss(toast), safeDelay);

        return toast;
    }

    /* -- API publique ----------------------------------------- */
    window.AppToast = { flash, dismiss };

}());
