<?php
function kam_mark(string $variant = 'reversed'): string
{
    if ($variant === 'reversed') {
        return '<img class="kam-mark" src="/assets/img/kam-team-logo-reversed.png" alt="KAM Team" width="679" height="212" draggable="false">';
    }
    return '<img class="kam-mark" src="/assets/img/kam-team-logo.png" alt="KAM Team" width="863" height="326" draggable="false">';
}

function kam_lockup(string $variant = 'reversed'): string
{
    return '<span class="lockup">' . kam_mark($variant) . '</span>';
}
