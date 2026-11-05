/**
 * Styles du Shadow DOM. Tout est thémable par variables CSS posées sur l'hôte
 * (`labyrinthe-otp { --lab-otp-primary: … }`) et par `::part()`.
 * Sarcelle #008374 et corail #F85A40 par défaut ; sombre via `theme="dark"`
 * ou automatiquement (`prefers-color-scheme`) sauf `theme="light"`.
 */
export const STYLES = `
:host{--lab-otp-primary:#008374;--lab-otp-primary-contrast:#fff;--lab-otp-accent:#F85A40;--lab-otp-error:#c2381f;
--lab-otp-success:#008374;--lab-otp-bg:#fff;--lab-otp-fg:#19201e;--lab-otp-muted:#5b6763;--lab-otp-border:#d5dcd9;
--lab-otp-field:#f8faf9;--lab-otp-radius:10px;--lab-otp-font:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
--lab-otp-cell-size:44px;--lab-otp-gap:8px;
display:block;max-width:420px;color:var(--lab-otp-fg);font:14px/1.45 var(--lab-otp-font);color-scheme:light}
:host([hidden]){display:none}
:host([theme=dark]){--lab-otp-primary:#2bb3a3;--lab-otp-primary-contrast:#06201c;--lab-otp-accent:#ff7a63;--lab-otp-error:#ff8a75;
--lab-otp-success:#2bb3a3;--lab-otp-bg:#19201e;--lab-otp-fg:#eef2f0;--lab-otp-muted:#a3b0ab;--lab-otp-border:#38433f;--lab-otp-field:#222b28;color-scheme:dark}
@media (prefers-color-scheme:dark){:host(:not([theme=light])){--lab-otp-primary:#2bb3a3;--lab-otp-primary-contrast:#06201c;
--lab-otp-accent:#ff7a63;--lab-otp-error:#ff8a75;--lab-otp-success:#2bb3a3;--lab-otp-bg:#19201e;--lab-otp-fg:#eef2f0;
--lab-otp-muted:#a3b0ab;--lab-otp-border:#38433f;--lab-otp-field:#222b28;color-scheme:dark}}
*{box-sizing:border-box}
.root{background:var(--lab-otp-bg);border:1px solid var(--lab-otp-border);border-radius:var(--lab-otp-radius);padding:20px}
h2{margin:0 0 12px;font-size:16px;font-weight:600}
form{display:flex;flex-direction:column;gap:10px;margin:0}
[hidden]{display:none!important}
label,.label{font-weight:500}
.hint,.muted{margin:0;color:var(--lab-otp-muted);font-size:13px}
.phone{display:flex;gap:var(--lab-otp-gap)}
input{font:inherit;color:inherit;background:var(--lab-otp-field);border:1px solid var(--lab-otp-border);
border-radius:calc(var(--lab-otp-radius) - 2px);padding:10px 12px;min-width:0;width:100%}
input:focus-visible,button:focus-visible{outline:2px solid var(--lab-otp-primary);outline-offset:2px}
input[aria-invalid=true]{border-color:var(--lab-otp-error)}
.cc{flex:0 0 5.5em;display:flex;align-items:center;gap:2px;background:var(--lab-otp-field);border:1px solid var(--lab-otp-border);
border-radius:calc(var(--lab-otp-radius) - 2px);padding-left:10px}
.cc input{border:0;padding-left:0;background:transparent}
.cc input:focus-visible{outline-offset:-2px}
.cells{display:flex;gap:var(--lab-otp-gap);flex-wrap:nowrap}
.cells input{width:var(--lab-otp-cell-size);height:calc(var(--lab-otp-cell-size) + 8px);padding:0;text-align:center;
font-size:20px;font-weight:600;font-variant-numeric:tabular-nums;flex:0 1 var(--lab-otp-cell-size)}
button{font:inherit;font-weight:600;border-radius:calc(var(--lab-otp-radius) - 2px);cursor:pointer;padding:10px 14px;
border:1px solid transparent}
button[disabled]{cursor:not-allowed;opacity:.6}
.primary{background:var(--lab-otp-primary);color:var(--lab-otp-primary-contrast)}
.primary:hover:not([disabled]){filter:brightness(1.08)}
.link{background:none;border:0;padding:4px 0;color:var(--lab-otp-primary);text-decoration:underline;text-underline-offset:2px;
align-self:flex-start;font-weight:500}
.row{display:flex;flex-wrap:wrap;justify-content:space-between;gap:4px 12px;align-items:center}
.error{margin:0;color:var(--lab-otp-error);font-weight:500}
.error:empty{display:none}
.test{margin:0;padding:6px 10px;border-radius:6px;border:1px dashed var(--lab-otp-accent);font-size:13px}
.success{display:flex;gap:10px;align-items:center;color:var(--lab-otp-success);font-weight:600}
.success svg{flex:none}
.sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media (max-width:360px){.root{padding:14px}:host{--lab-otp-cell-size:36px;--lab-otp-gap:6px}}
@media (prefers-reduced-motion:no-preference){button,input{transition:border-color .15s,filter .15s}}
`;
