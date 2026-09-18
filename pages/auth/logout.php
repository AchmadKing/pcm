<?php
/**
 * Logout Handler
 * PCC - Project Cost Control System
 */

session_start();
session_unset();
session_destroy();

header('Location: login.php');
exit;
