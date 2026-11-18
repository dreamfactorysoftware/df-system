<?php

return [
    // Comma-separated hostnames, IP addresses and CIDR ranges on the internal network that remote URLs may
    // point at, e.g. "soap.corp.local,10.20.0.0/16". Applies to SOAP WSDL, endpoint and proxy hosts, package,
    // app and data import URLs, file uploads and email attachments given by URL, and redirects followed by
    // outbound requests. Hostnames match exactly; addresses and ranges match the address the host resolves
    // to. List only the destinations DreamFactory needs. Empty by default.
    'outbound_allowed_hosts' => env('DF_OUTBOUND_ALLOWED_HOSTS', ''),
];
