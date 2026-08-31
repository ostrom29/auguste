/*
 * Audit des contrastes tels que le navigateur les calcule réellement.
 *
 *   cd <dossier de la compétence browser-automation>
 *   NODE_PATH=~/outils-navigateur/node_modules \
 *     node browser.mjs https://chezauguste.com/ \
 *     --script <ce fichier>
 *
 * outils/contraste.py vérifie la palette telle qu'elle est déclarée. Ce
 * script vérifie les couleurs telles qu'elles sont appliquées — la différence
 * n'est pas théorique : une règle plus spécifique peut en remplacer une autre
 * sans que personne ne s'en aperçoive, et c'est exactement ce qui rendait les
 * liens du pied de page illisibles alors que la palette était conforme.
 */

const PAGES = ['/', '/carte.html', '/contact.php', '/mentions-legales.html'];

export default async function run(page) {
  const origine = new URL(page.url()).origin;
  const problemes = [];

  for (const chemin of PAGES) {
    await page.goto(origine + chemin, { waitUntil: 'networkidle' });

    const trouves = await page.evaluate(() => {
      const luminance = (rgb) => {
        const c = rgb.map((v) => {
          v /= 255;
          return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
      };

      const nombres = (s) => (s.match(/[\d.]+/g) || []).map(Number);

      const composer = (avant, alpha, arriere) =>
        avant.map((v, i) => Math.round(v * alpha + arriere[i] * (1 - alpha)));

      /* Le fond réel d'un élément : on remonte ses ancêtres en composant les
         couches semi-transparentes au lieu de les prendre pour opaques. */
      const fond = (element) => {
        const couches = [];

        for (let n = element; n; n = n.parentElement) {
          const v = nombres(getComputedStyle(n).backgroundColor);
          if (v.length < 3) continue;
          const alpha = v.length > 3 ? v[3] : 1;
          if (alpha === 0) continue;
          couches.push([v.slice(0, 3), alpha]);
          if (alpha === 1) break;
        }

        let resultat = [255, 255, 255];
        for (let i = couches.length - 1; i >= 0; i--) {
          resultat = composer(couches[i][0], couches[i][1], resultat);
        }
        return resultat;
      };

      const sorties = [];

      document.querySelectorAll('body *').forEach((el) => {
        const texte = Array.from(el.childNodes)
          .filter((n) => n.nodeType === 3)
          .map((n) => n.textContent.trim())
          .join(' ')
          .trim();

        if (!texte) return;

        const style = getComputedStyle(el);
        if (style.display === 'none' || style.visibility === 'hidden') return;
        if (parseFloat(style.opacity) < 0.1) return;

        const c = nombres(style.color);
        const arriere = fond(el);
        const avant = c.length > 3 && c[3] < 1
          ? composer(c.slice(0, 3), c[3], arriere)
          : c.slice(0, 3);

        const la = luminance(avant);
        const lb = luminance(arriere);
        const ratio = (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);

        // WCAG abaisse le seuil pour le grand texte.
        const px = parseFloat(style.fontSize);
        const gras = parseInt(style.fontWeight, 10) >= 700;
        const seuil = px >= 24 || (px >= 18.66 && gras) ? 3 : 4.5;

        if (ratio < seuil) {
          sorties.push({
            element: el.className || el.tagName.toLowerCase(),
            texte: texte.slice(0, 40),
            ratio: Math.round(ratio * 100) / 100,
            seuil,
          });
        }
      });

      return sorties;
    });

    trouves.forEach((t) => problemes.push({ page: chemin, ...t }));
  }

  return problemes.length === 0
    ? { verdict: 'tous les textes atteignent leur seuil', pages: PAGES.length }
    : { verdict: `${problemes.length} texte(s) sous le seuil`, problemes };
}
