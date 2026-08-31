#!/usr/bin/env python3
"""
Contrôle les contrastes de la palette.

    python3 outils/contraste.py

Le rapport de contraste WCAG ne demande pas d'yeux : c'est de l'arithmétique
sur les couleurs. C'est donc la partie du travail visuel qui se vérifie sans
regarder l'écran, et elle a sa place dans src/verif.sh.

Seuils : 4,5 pour du texte, 3 pour un grand texte ou la bordure d'un élément
interactif. Un trait purement décoratif n'a pas de seuil — mais la bordure
d'un champ de saisie en a un, parce qu'il faut voir où l'on écrit.

Les couleurs sont lues dans public/style.css, pour qu'une modification de la
palette soit vérifiée telle qu'elle est réellement écrite.
"""

import re
import sys
from pathlib import Path

CSS = Path(__file__).resolve().parent.parent / "public" / "style.css"


def canal(v: float) -> float:
    v /= 255
    return v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4


def luminance(rgb: tuple) -> float:
    r, g, b = (canal(c) for c in rgb)
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def hexa(h: str) -> tuple:
    h = h.lstrip("#")
    if len(h) == 3:
        h = "".join(c * 2 for c in h)
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def sur(avant: tuple, fond: tuple, alpha: float) -> tuple:
    """Compose une couleur semi-transparente sur son fond."""
    return tuple(round(a * alpha + f * (1 - alpha)) for a, f in zip(avant, fond))


def rapport(a: tuple, b: tuple) -> float:
    la, lb = luminance(a), luminance(b)
    return (max(la, lb) + 0.05) / (min(la, lb) + 0.05)


def palette() -> dict:
    """Lit les variables de couleur déclarées dans la feuille de style."""
    texte = CSS.read_text(encoding="utf-8")
    trouvees = re.findall(r"--([\w-]+):\s*(#[0-9a-fA-F]{3,6})\s*;", texte)

    if not trouvees:
        print("Aucune couleur trouvée dans style.css", file=sys.stderr)
        sys.exit(1)

    return {nom: hexa(valeur) for nom, valeur in trouvees}


def main() -> None:
    c = palette()

    paires = [
        ("texte courant", c["encre"], c["creme"], 4.5),
        ("texte secondaire", c["encre-douce"], c["creme"], 4.5),
        ("rouge de marque", c["rouge"], c["creme"], 4.5),
        ("rouge sur bandeau", c["rouge"], c["creme-fonce"], 4.5),
        ("crème sur bordeaux", c["creme"], c["bordeaux"], 4.5),
        ("horaires du pied (72 %)", sur(c["creme"], c["bordeaux"], 0.72), c["bordeaux"], 4.5),
        ("bouton : crème sur rouge", c["creme"], c["rouge"], 4.5),
        ("bouton survolé", c["creme"], c["rouge-sombre"], 4.5),
        # La bordure d'un champ est un élément d'interface, pas un décor.
        ("bordure de champ", c["filet-champ"], c["creme"], 3.0),
    ]

    echecs = 0

    for nom, avant, fond, seuil in paires:
        r = rapport(avant, fond)
        ok = r >= seuil
        echecs += 0 if ok else 1
        print(f"  {'ok' if ok else 'KO'}   {nom:<26} {r:5.2f}:1  (seuil {seuil})")

    print()
    print("  Contrastes conformes." if echecs == 0 else f"  {echecs} contraste(s) insuffisant(s).")
    sys.exit(1 if echecs else 0)


if __name__ == "__main__":
    main()
