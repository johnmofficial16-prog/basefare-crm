"""Builds public/assets/css/theme.css — the CRM's colour tokens.

Every Tailwind colour the CRM uses is pointed at a CSS variable (see
public/assets/js/theme.js), so one stylesheet decides what "slate-100" or
"white" means. Light = Peacock (Tailwind's own palette plus the new brand
tokens); dark = Night Shift, where the scales are mirrored so a pale badge
becomes a deep tinted one and dark text becomes light text.

Run after changing a value here:  python scripts/build_theme_css.py
"""
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'public', 'assets', 'css', 'theme.css')

SHADES = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950']

# Tailwind v3.4 default palette.
TW = {
    'slate':   ['#f8fafc', '#f1f5f9', '#e2e8f0', '#cbd5e1', '#94a3b8', '#64748b', '#475569', '#334155', '#1e293b', '#0f172a', '#020617'],
    'gray':    ['#f9fafb', '#f3f4f6', '#e5e7eb', '#d1d5db', '#9ca3af', '#6b7280', '#4b5563', '#374151', '#1f2937', '#111827', '#030712'],
    'zinc':    ['#fafafa', '#f4f4f5', '#e4e4e7', '#d4d4d8', '#a1a1aa', '#71717a', '#52525b', '#3f3f46', '#27272a', '#18181b', '#09090b'],
    'neutral': ['#fafafa', '#f5f5f5', '#e5e5e5', '#d4d4d4', '#a3a3a3', '#737373', '#525252', '#404040', '#262626', '#171717', '#0a0a0a'],
    'stone':   ['#fafaf9', '#f5f5f4', '#e7e5e4', '#d6d3d1', '#a8a29e', '#78716c', '#57534e', '#44403c', '#292524', '#1c1917', '#0c0a09'],
    'red':     ['#fef2f2', '#fee2e2', '#fecaca', '#fca5a5', '#f87171', '#ef4444', '#dc2626', '#b91c1c', '#991b1b', '#7f1d1d', '#450a0a'],
    'orange':  ['#fff7ed', '#ffedd5', '#fed7aa', '#fdba74', '#fb923c', '#f97316', '#ea580c', '#c2410c', '#9a3412', '#7c2d12', '#431407'],
    'amber':   ['#fffbeb', '#fef3c7', '#fde68a', '#fcd34d', '#fbbf24', '#f59e0b', '#d97706', '#b45309', '#92400e', '#78350f', '#451a03'],
    'yellow':  ['#fefce8', '#fef9c3', '#fef08a', '#fde047', '#facc15', '#eab308', '#ca8a04', '#a16207', '#854d0e', '#713f12', '#422006'],
    'lime':    ['#f7fee7', '#ecfccb', '#d9f99d', '#bef264', '#a3e635', '#84cc16', '#65a30d', '#4d7c0f', '#3f6212', '#365314', '#1a2e05'],
    'green':   ['#f0fdf4', '#dcfce7', '#bbf7d0', '#86efac', '#4ade80', '#22c55e', '#16a34a', '#15803d', '#166534', '#14532d', '#052e16'],
    'emerald': ['#ecfdf5', '#d1fae5', '#a7f3d0', '#6ee7b7', '#34d399', '#10b981', '#059669', '#047857', '#065f46', '#064e3b', '#022c22'],
    'teal':    ['#f0fdfa', '#ccfbf1', '#99f6e4', '#5eead4', '#2dd4bf', '#14b8a6', '#0d9488', '#0f766e', '#115e59', '#134e4a', '#042f2e'],
    'cyan':    ['#ecfeff', '#cffafe', '#a5f3fc', '#67e8f9', '#22d3ee', '#06b6d4', '#0891b2', '#0e7490', '#155e75', '#164e63', '#083344'],
    'sky':     ['#f0f9ff', '#e0f2fe', '#bae6fd', '#7dd3fc', '#38bdf8', '#0ea5e9', '#0284c7', '#0369a1', '#075985', '#0c4a6e', '#082f49'],
    'blue':    ['#eff6ff', '#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa', '#3b82f6', '#2563eb', '#1d4ed8', '#1e40af', '#1e3a8a', '#172554'],
    'indigo':  ['#eef2ff', '#e0e7ff', '#c7d2fe', '#a5b4fc', '#818cf8', '#6366f1', '#4f46e5', '#4338ca', '#3730a3', '#312e81', '#1e1b4b'],
    'violet':  ['#f5f3ff', '#ede9fe', '#ddd6fe', '#c4b5fd', '#a78bfa', '#8b5cf6', '#7c3aed', '#6d28d9', '#5b21b6', '#4c1d95', '#2e1065'],
    'purple':  ['#faf5ff', '#f3e8ff', '#e9d5ff', '#d8b4fe', '#c084fc', '#a855f7', '#9333ea', '#7e22ce', '#6b21a8', '#581c87', '#3b0764'],
    'fuchsia': ['#fdf4ff', '#fae8ff', '#f5d0fe', '#f0abfc', '#e879f9', '#d946ef', '#c026d3', '#a21caf', '#86198f', '#701a75', '#4a044e'],
    'pink':    ['#fdf2f8', '#fce7f3', '#fbcfe8', '#f9a8d4', '#f472b6', '#ec4899', '#db2777', '#be185d', '#9d174d', '#831843', '#500724'],
    'rose':    ['#fff1f2', '#ffe4e6', '#fecdd3', '#fda4af', '#fb7185', '#f43f5e', '#e11d48', '#be123c', '#9f1239', '#881337', '#4c0519'],
}
NEUTRALS = {'slate', 'gray', 'zinc', 'neutral', 'stone'}

# Night Shift surfaces.
SURFACE = '#111A2E'      # cards ("white" in dark)
PAGE = '#0A1020'         # page background
NEUTRAL_DARK = ['#142038', '#182540', '#22304D', '#33425F', '#8A98B4', '#97A5BF', '#A9B6CC', '#BCC7DA', '#D3DBE8', '#E8EDF7', '#F3F6FB']

# Light-mode greys 400/500 are darker than Tailwind's: the CRM uses them for
# secondary text, and Tailwind's slate-400 is only 2.6:1 on white (audit, 24 Oct).
LIGHT_TEXT_GREYS = {'400': '#5F6E84', '500': '#55647B'}


def rgb(h):
    h = h.lstrip('#')
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def mix(a, b, t):
    """t of colour a over colour b."""
    a, b = rgb(a), rgb(b)
    return '#%02x%02x%02x' % tuple(round(a[i] * t + b[i] * (1 - t)) for i in range(3))


def trip(h):
    return '%d %d %d' % rgb(h)


def dark_scale(name):
    if name in NEUTRALS:
        return NEUTRAL_DARK
    c = dict(zip(SHADES, TW[name]))
    return [
        mix(c['500'], SURFACE, .14), mix(c['500'], SURFACE, .20), mix(c['500'], SURFACE, .32),
        mix(c['400'], SURFACE, .60), c['400'], c['400'], c['300'], mix(c['300'], c['200'], .5),
        c['200'], c['100'], c['50'],
    ]


# Brand tokens used by the views' own tailwind.config names.
TOKENS = {
    #                          Peacock (light)  Night Shift (dark)
    'primary':                 ('#1A5CC8', '#4DA3FF'),
    'primary-container':       ('#2F74E8', '#7FB4FF'),
    'on-primary':              ('#FFFFFF', '#06101F'),
    'background':              ('#F3F5F9', PAGE),
    'surface':                 ('#F3F5F9', PAGE),
    'surface-container-lowest': ('#FFFFFF', SURFACE),
    'surface-container-low':   ('#EEF1F6', '#142038'),
    'surface-container':       ('#E7EBF2', '#182540'),
    'surface-container-high':  ('#DFE4EC', '#1F2C49'),
    'on-surface':              ('#0E1526', '#E8EDF7'),
    'on-surface-variant':      ('#4A556B', '#9AA8C2'),
    'outline-variant':         ('#C8D0DD', '#2C3956'),
    'error':                   ('#B42318', '#FCA5A5'),
    'error-container':         ('#FDE8EC', '#3A1620'),
    'secondary':               ('#4A6774', '#9FC2D2'),
    'secondary-container':     ('#DCEBFF', '#16264A'),
    'on-secondary-container':  ('#23466F', '#9CC3FF'),
    'gold':                    ('#C9A84C', '#E2C877'),
    'gold-light':              ('#F5E6C0', '#3A3220'),
    'on-gold':                 ('#14213D', '#1A1405'),
    'placeholder':             ('#6B7280', '#7D8BA8'),
    'faint':                   ('#7F8CA1', '#74839F'),   # text-slate-300: dashes, empty values
    'ink':                     ('#0E1526', '#E8EDF7'),
    'on-background':           ('#0E1526', '#E8EDF7'),
    'outline':                 ('#737784', '#7D8BA8'),
    'surface-variant':         ('#E1E3E4', '#1F2C49'),
    'surface-tint':            ('#435B9F', '#7FB4FF'),
    'surface-dim':             ('#D9DADB', '#0A1020'),
    'surface-bright':          ('#F8F9FA', '#1F2C49'),
    'surface-container-highest': ('#E1E3E4', '#24324F'),
    'primary-fixed':           ('#DCE8FD', '#16264A'),
    'primary-fixed-dim':       ('#B3C5FF', '#2C4A80'),
    'on-primary-fixed':        ('#00174A', '#DCE8FD'),
    'on-primary-fixed-variant': ('#2A4386', '#9CC3FF'),
    'on-primary-container':    ('#DCE8FD', '#0A1A38'),
    'secondary-fixed':         ('#C9E7F7', '#16304A'),
    'secondary-fixed-dim':     ('#ADCBDA', '#2A4A60'),
    'on-secondary':            ('#FFFFFF', '#0A1020'),
    'on-secondary-fixed':      ('#001F2A', '#C9E7F7'),
    'on-secondary-fixed-variant': ('#2E4B57', '#ADCBDA'),
    'tertiary':                ('#651F00', '#FFB59A'),
    'tertiary-container':      ('#8B2E01', '#FFB59A'),
    'tertiary-fixed':          ('#FFDBCF', '#3A2016'),
    'tertiary-fixed-dim':      ('#FFB59A', '#8A4A30'),
    'on-tertiary':             ('#FFFFFF', '#2A1000'),
    'on-tertiary-container':   ('#FFAA8A', '#380D00'),
    'on-tertiary-fixed':       ('#380D00', '#FFDBCF'),
    'on-tertiary-fixed-variant': ('#802900', '#FFB59A'),
    'on-error':                ('#FFFFFF', '#3A1620'),
    'on-error-container':      ('#93000A', '#FECDD3'),
    'inverse-surface':         ('#2E3132', '#E8EDF7'),
    'inverse-on-surface':      ('#F0F1F2', '#111A2E'),
    'inverse-primary':         ('#B3C5FF', '#1A5CC8'),
    'white':                   ('#FFFFFF', SURFACE),
    'black':                   ('#000000', '#000000'),
    'side':                    ('#0A0F1C', '#060A14'),   # the sidebar, dark in both
    'glow-1':                  ('#1F6FEB', '#1F6FEB'),   # backdrop colour behind the glass
    'glow-2':                  ('#0F9D8A', '#0F9D8A'),
    'glow-3':                  ('#6D4AFF', '#6D4AFF'),
    'side-line':               ('#141D33', '#141D33'),
}


def block(dark):
    lines = []
    for name in TW:
        vals = dark_scale(name) if dark else [
            LIGHT_TEXT_GREYS.get(s, v) if name in ('slate', 'gray') else v for s, v in zip(SHADES, TW[name])]
        lines.append('  ' + ' '.join('--bf-%s-%s:%s;' % (name, s, trip(v)) for s, v in zip(SHADES, vals)))
    lines.append('  ' + ' '.join('--bf-%s:%s;' % (k, trip(v[1] if dark else v[0])) for k, v in TOKENS.items()))
    # Glass: how strong the backdrop colour is, and how opaque the frosted cards are.
    lines.append('  --bf-glow-a:%s; --bf-glass-a:%s;' % (('.30', '.60') if dark else ('.16', '.78')))
    return '\n'.join(lines)


# Hard-coded hex classes still in the views, pointed at the tokens so they follow
# the theme. (Generated by Tailwind at runtime; `html` in front outranks it.)
ARBITRARY = [
    ('bg', '#E3F2FD', 'var(--bf-blue-50)'), ('text', '#1565C0', 'var(--bf-blue-700)'),
    ('bg', '#F5F5F5', 'var(--bf-slate-100)'), ('text', '#757575', 'var(--bf-slate-500)'),
    ('bg', '#0f2557', 'var(--bf-primary)'), ('text', '#163274', 'var(--bf-primary)'),
    ('text', '#F57F17', 'var(--bf-amber-700)'), ('text', '#434653', 'var(--bf-on-surface-variant)'),
    ('text', '#283593', 'var(--bf-indigo-700)'), ('bg', '#f8f9fa', 'var(--bf-background)'),
    ('bg', '#f3f4f5', 'var(--bf-surface-container-low)'), ('bg', '#FFF8E1', 'var(--bf-amber-50)'),
    ('bg', '#E8EAF6', 'var(--bf-indigo-50)'), ('bg', '#163274', 'var(--bf-primary)'),
    ('border', '#163274', 'var(--bf-primary)'), ('bg', '#e8edf9', 'var(--bf-blue-50)'),
    ('text', '#191c1d', 'var(--bf-on-surface)'), ('bg', '#f5f6fa', 'var(--bf-background)'),
    ('bg', '#F57F17', 'var(--bf-amber-600)'), ('bg', '#314a8d', 'var(--bf-primary-container)'),
    ('bg', '#283593', 'var(--bf-indigo-700)'), ('bg', '#1565C0', 'var(--bf-blue-700)'),
    ('bg', '#f4f6fb', 'var(--bf-background)'),
]
PROP = {'bg': 'background-color', 'text': 'color', 'border': 'border-color'}


def esc(cls):
    return cls.replace('[', '\\[').replace(']', '\\]').replace('#', '\\#')


def arbitrary_rules():
    out = []
    for kind, hexv, var in ARBITRARY:
        names = sorted({hexv, hexv.lower(), hexv.upper()})
        sel = ', '.join('html .%s' % esc('%s-[%s]' % (kind, n)) for n in names)
        out.append('%s { %s: rgb(%s); }' % (sel, PROP[kind], var))
    names = sorted({'#314a8d', '#314A8D'})
    out.append(', '.join('html .%s' % esc('to-[%s]' % n) for n in names)
               + ' { --tw-gradient-to: rgb(var(--bf-primary-container)) var(--tw-gradient-to-position); }')
    return '\n'.join(out)


css = """/* Base Fare CRM theme — GENERATED by scripts/build_theme_css.py. Edit that, not this.
 * Light = Peacock, dark = Night Shift. <html data-theme> picks one; an element
 * with its own data-theme (the sidebar, document previews) is an island. */
:root, [data-theme="light"] {
%s
  color-scheme: light;
}
[data-theme="dark"] {
%s
  color-scheme: dark;
}
/* Paper is white: anything printed uses the light colours, even from dark mode. */
@media print {
  [data-theme="dark"] {
%s
    color-scheme: light;
  }
}

/* Hard-coded colours in older views, routed through the tokens. */
%s
""" % (block(False), block(True), block(False).replace('\n', '\n  '), arbitrary_rules())

# Coloured TEXT in mid shades (text-rose-500, text-emerald-500…) is too pale on
# white to read; send text-only classes to the first shade that passes 4.5:1.
# Backgrounds, borders and dots keep their shade. Dark mode follows the tokens.
TEXT_SHADE = {'red': '600', 'rose': '600', 'pink': '600', 'fuchsia': '600', 'purple': '600', 'violet': '600',
              'indigo': '600', 'blue': '600', 'sky': '700', 'cyan': '700', 'teal': '700', 'emerald': '700',
              'green': '700', 'lime': '700', 'yellow': '700', 'amber': '700', 'orange': '700'}
rules = []
for pal, target in TEXT_SHADE.items():
    for shade in ('400', '500', '600'):
        if int(shade) >= int(target):
            continue
        rules.append('html .text-%s-%s { color: rgb(var(--bf-%s-%s)); }' % (pal, shade, pal, target))
css += '\n/* Readable coloured text (see TEXT_SHADE). */\n' + '\n'.join(rules) + '\n'


def contrast(h1, h2):
    def lum(h):
        c = [x / 255 for x in rgb(h)]
        c = [x / 12.92 if x <= 0.03928 else ((x + 0.055) / 1.055) ** 2.4 for x in c]
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]
    x, y = lum(h1), lum(h2)
    return (max(x, y) + .05) / (min(x, y) + .05)


# White text on a mid-tone fill (bg-emerald-500 text-white...) is under 4.5:1 in
# Tailwind's palette. In light mode, when an element has both, deepen the fill to
# the first shade that passes. (Dark mode inverts these and is fine.)
fills = []
for pal, vals in TW.items():
    if pal in NEUTRALS:
        continue
    shade_of = dict(zip(SHADES, vals))
    for sh in ('400', '500', '600'):
        if contrast(shade_of[sh], '#ffffff') >= 4.5:
            continue
        t = next(x for x in ('600', '700', '800') if int(x) > int(sh) and contrast(shade_of[x], '#ffffff') >= 4.5)
        fills.append('html[data-theme="light"] .text-white.bg-%s-%s { background-color: rgb(var(--bf-%s-%s)); }' % (pal, sh, pal, t))
css += '\n/* White text on mid-tone fills, light mode. */\n' + '\n'.join(fills) + '\n'

soft = []
for pal in TW:
    if pal in NEUTRALS:
        continue
    for sh in ('600', '700', '800'):
        for al in ('50', '60', '70', '75', '80'):
            cls = 'text-%s-%s\\/%s' % (pal, sh, al)
            soft.append('html .%s { color: rgb(var(--bf-%s-%s)); }' % (cls, pal, sh))
css += '\n/* Softened coloured text loses too much contrast: shown at full strength. */\n' + '\n'.join(soft) + '\n'

css += open(os.path.join(ROOT, 'scripts', 'theme_base.css'), encoding='utf-8').read()
open(OUT, 'w', encoding='utf-8', newline='\n').write(css)
print('wrote', OUT, len(css), 'bytes')
