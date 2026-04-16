<?php
session_start();
require_once '../includes/auth.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}
requireAdministratorAccess();

require_once '../modules/portal/PortalProfileController.php';

$portalViewData = portalHandleProfilesRequest();
renderPortalProfilesPage($portalViewData);
