<?php
/**
 * Logout Handler
 * PCM - Project Cost Management System
 */

session_start();
session_unset();
session_destroy();

header('Location: login.php');
exit;
