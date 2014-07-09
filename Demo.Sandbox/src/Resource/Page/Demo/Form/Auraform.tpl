{extends file="layout/demo.tpl"}
{block name=title}Aura Form{/block}

{block name=page}
    {if $code === 201}
        Name: {$name|escape}<br>
        Email: {$email|escape}<br>
        URL: {$url|escape}<br>
        Message: {$message|escape}<br>
    {else}
        <form role="form" action="/demo/form/auraform" method="POST" enctype="multipart/form-data">
            <input name="_method" type="hidden" value="POST">
            {form hint=$form.__csrf_token.hint}

            <div class="form-group {if $form.name.error}has-error{/if}">
                <label class="control-label" for="name">Name</label>
                {form hint=$form.name.hint}
                <label class="control-label" for="name">{$form.name.error}</label>
            </div>

            <div class="form-group {if $form.email.error}has-error{/if}">
                <label class="control-label" for="email">Email</label>
                {form hint=$form.email.hint}
                <label class="control-label" for="email">{$form.email.error}</label>
            </div>

            <div class="form-group {if $form.url.error}has-error{/if}">
                <label class="control-label" for="url">URL</label>
                {form hint=$form.url.hint}
                <label class="control-label" for="url">{$form.url.error}</label>
            </div>

            <div class="form-group {if $form.message.error}has-error{/if}">
                <label class="control-label" for="message">Message</label>
                {form hint=$form.message.hint}
                <label class="control-label" for="message">{$form.message.error}</label>
            </div>

            <input type="submit" name="submit" value="send">
        </form>
    {/if}
{/block}
