<?php
// Decoy: nothing legitimate ever asks for this address. Whoever does is probing the site and is blocked (includes/ipguard.php).
require_once __DIR__ . '/includes/bootstrap.php';
ipguard_decoy();
