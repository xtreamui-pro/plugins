{if $error}
    <div class="alert alert-warning">
        <strong>Your IPTV line could not be loaded.</strong>
        {$error|escape}
    </div>
{else}
    <div class="panel panel-default card">
        <div class="panel-heading card-header">
            <h3 class="panel-title card-title">IPTV line</h3>
        </div>
        <div class="panel-body card-body">
            <table class="table table-condensed">
                <tbody>
                    <tr>
                        <th>Status</th>
                        <td>{$status|escape}</td>
                    </tr>
                    <tr>
                        <th>Expires</th>
                        <td>{$expiry|escape}</td>
                    </tr>
                    <tr>
                        <th>Max connections</th>
                        <td>{$maxConnections|escape}</td>
                    </tr>
                    <tr>
                        <th>Server URL</th>
                        <td><code>{$serverUrl|escape}</code></td>
                    </tr>
                    <tr>
                        <th>Username</th>
                        <td><code>{$username|escape}</code></td>
                    </tr>
                    <tr>
                        <th>Password</th>
                        <td><code>{$password|escape}</code></td>
                    </tr>
                    {if $playlistUrl}
                    <tr>
                        <th>M3U playlist</th>
                        <td><input type="text" class="form-control" readonly value="{$playlistUrl|escape}"></td>
                    </tr>
                    {/if}
                    {if $hlsUrl}
                    <tr>
                        <th>M3U playlist (HLS)</th>
                        <td><input type="text" class="form-control" readonly value="{$hlsUrl|escape}"></td>
                    </tr>
                    {/if}
                    {if $epgUrl}
                    <tr>
                        <th>Programme guide (XMLTV)</th>
                        <td><input type="text" class="form-control" readonly value="{$epgUrl|escape}"></td>
                    </tr>
                    {/if}
                    {if $playerUrl}
                    <tr>
                        <th>Web player</th>
                        <td><a href="{$playerUrl|escape}" target="_blank" rel="noopener noreferrer">{$playerUrl|escape}</a></td>
                    </tr>
                    {/if}
                </tbody>
            </table>
            <p class="text-muted small">
                For Xtream-codes apps use the server URL, username and password above.
            </p>
        </div>
    </div>
{/if}
