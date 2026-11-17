<?php

return [
    // Comma-separated hostnames, IP addresses and CIDR ranges on the internal network that remote URLs
    // (package and app import sources, SOAP endpoints) may point at, e.g. "soap.corp.local,10.20.0.0/16".
    // Hostnames match exactly; addresses and ranges match the address the URL host resolves to. Empty by default.
    'outbound_allowed_hosts' => env('DF_OUTBOUND_ALLOWED_HOSTS', ''),
];
