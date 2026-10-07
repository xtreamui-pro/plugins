<?php
/**
 * Copy to config.php next to xtreampro-provision.php and run:
 *     chmod 600 config.php
 * (the script refuses to run when the file is readable by other users).
 *
 * Instead of this file you can set the environment variables
 * XTREAMPRO_API_URL / XTREAMPRO_API_KEY (and XTREAMPRO_DATA_DIR). The API key is
 * never read from the command line.
 */
return array(
    // Address of the panel's API (cmd/api), no trailing path. Use https.
    'api_url'  => 'https://api.example.com',

    // The RESELLER's API key (dashboard: /api-key while signed in as the reseller).
    'api_key'  => 'PASTE-THE-RESELLER-API-KEY-HERE',

    // Where the service id -> line id / account id table is kept (state.json).
    // Leave empty for a "data" folder next to the script. Must be writable by
    // the user that runs the script and not readable by others.
    'data_dir' => '',
);
