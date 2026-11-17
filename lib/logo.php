<?php
// $variant: 'reversed' = Sand + Orange on Ink, 'default' = Ink + Orange on Paper/Sand.
function kam_mark(string $variant = 'default'): string
{
    if ($variant === 'reversed') {
        return '<img class="kam-mark" src="assets/img/kam-team-lockup-reversed.png" alt="KAM Team" width="1360" height="426" draggable="false">';
    }
    return '<img class="kam-mark" src="assets/img/kam-team-lockup.png" alt="KAM Team" width="1360" height="426" draggable="false">';
}

function kam_lockup(string $variant = 'default'): string
{
    return '<span class="lockup">' . kam_mark($variant) . '</span>';
}
