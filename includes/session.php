<?php
// Basic safe session start
if (session_status() === PHP_SESSION_NONE) {
  ini_set('session.use_strict_mode', 1);
  session_start();
}