@php($display = $getRecord()->gravityzoneLicenseDisplay())
@php($checkedAt = $getRecord()->license_checked_at?->copy()->timezone('Europe/Rome')->format('d/m/Y H:i'))
<div class="bl-computer-license-status">
    <x-filament::badge
        :color="$display['color']"
        :tag="$checkedAt ? 'button' : 'span'"
        :data-checked-at="$checkedAt"
        :x-tooltip="$checkedAt ? '{ content: $el.dataset.checkedAt, theme: $store.theme, trigger: \'mouseenter focus click\' }' : null"
        x-on:click.stop
    >{{ $display['label'] }}</x-filament::badge>
</div>
