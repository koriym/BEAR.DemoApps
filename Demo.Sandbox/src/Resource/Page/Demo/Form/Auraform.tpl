{extends file="layout/demo.tpl"}
{block name=title}Aura Form{/block}

{block name=page}
    {if $code === 201}
        Name:{$name}<br>
        Email:{$email}<br>
        URL:{$url}<br>
        Message:{$message}<br>
    {else}
        <form role="form" action="/demo/form/auraform" method="POST" enctype="multipart/form-data">
            <input name="_method" type="hidden" value="POST">

            <div class="form-group {if $form.name.error}has-error{/if}">
                <label class="control-label" for="name">Name</label>
                {form type="field" name=$name}
                <label class="control-label" for="name">{$form.name.error}</label>
            </div>

            <div class="form-group {if $form.email.error}has-error{/if}">
                <label class="control-label" for="email">Email</label>
                {form type="field" name=$email}
                <label class="control-label" for="email">{$form.email.error}</label>
            </div>

            <div class="form-group {if $form.url.error}has-error{/if}">
                <label class="control-label" for="url">URL</label>
                {form type="field" name=$url}
                <label class="control-label" for="url">{$form.url.error}</label>
            </div>

            <div class="form-group {if $form.message.error}has-error{/if}">
                <label class="control-label" for="message">Message</label>
                {form type="field" name=$message}
                <label class="control-label" for="message">{$form.message.error}</label>
            </div>

            <input type="submit" name="submit" value="send">
        </form>
    {/if}
{/block}
