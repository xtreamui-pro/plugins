{if $error}
    <div class="alert alert-warning">
        <strong>Your reseller account could not be loaded.</strong>
        {$error|escape}
    </div>
{else}
    <div class="panel panel-default card">
        <div class="panel-heading card-header">
            <h3 class="panel-title card-title">Reseller account</h3>
        </div>
        <div class="panel-body card-body">
            <table class="table table-condensed">
                <tbody>
                    <tr>
                        <th>Status</th>
                        <td>{$status|escape}</td>
                    </tr>
                    <tr>
                        <th>Credit balance</th>
                        <td>{$credits|escape}</td>
                    </tr>
                    <tr>
                        <th>Username</th>
                        <td><code>{$username|escape}</code></td>
                    </tr>
                    <tr>
                        <th>Password</th>
                        <td><code>{$password|escape}</code></td>
                    </tr>
                    {if $loginUrl}
                    <tr>
                        <th>Sign in</th>
                        <td><a href="{$loginUrl|escape}" target="_blank" rel="noopener noreferrer">{$loginUrl|escape}</a></td>
                    </tr>
                    {/if}
                </tbody>
            </table>
        </div>
    </div>
{/if}
