/** Applies the configurable brand colour and the readable text colour that goes on top of it. */
export function applyBrand(color?: string | null, ink?: string | null) {
    const root = document.documentElement.style;
    if (color && /^#[0-9a-fA-F]{6}$/.test(color)) root.setProperty('--brand', color);
    if (ink && /^#[0-9a-fA-F]{6}$/.test(ink)) root.setProperty('--brand-ink', ink);
}
