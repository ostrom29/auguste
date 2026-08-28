#!/usr/bin/env python3
"""
Prépare les images du site à partir de medias-sources/.

    python3 outils/images.py

Lit les originaux, écrit dans public/img/ des versions dimensionnées pour le
web. Les originaux ne sont pas versionnés, les sorties le sont.

Comme outils/paquet.py, c'est un outil de la machine du développeur : le site
servi reste du HTML et du CSS statiques, rien n'est traité à la volée.

Dépend d'ImageMagick (`convert`), déjà présent sur la machine de dev.
"""

import shutil
import subprocess
import sys
from pathlib import Path

RACINE = Path(__file__).resolve().parent.parent
SOURCES = RACINE / "medias-sources"
SORTIE = RACINE / "public" / "img"

# Le logo est fourni en JPEG sur fond blanc : pas d'alpha possible dans ce
# format. Un « -transparent white » donne un alpha moucheté, parce que le fond
# d'un JPEG n'est jamais parfaitement uniforme.
#
# On le reconstruit donc : le dessin est monochrome, sa luminance inversée
# fait un masque alpha propre, qu'on applique à un aplat du rouge de marque.
# Bords nets, couleur exacte, fichier léger.
#
# Un SVG, ou un PNG à canal alpha d'origine, rendrait tout ceci inutile.
LOGO_ROUGE = "#A01E23"

# Ce qui est plus clair que le haut de la plage devient transparent, ce qui est
# plus sombre que le bas devient opaque.
#
# Le seuil bas est haut parce que le fond du JPEG fourni n'est pas blanc : ses
# coins sont à rgb(205,205,205). Un seuil plus doux laisserait ce gris dans le
# masque et empêcherait le recadrage.
LOGO_PLAGE = "30%,50%"

# La photo source ne fait que 679 px de large : on ne l'agrandit pas, ça ne
# ferait qu'ajouter du flou et du poids.
SALLE_LARGEURS = (340, 480, 679)


def executer(arguments: list[str]) -> None:
    resultat = subprocess.run(arguments, capture_output=True, text=True)

    if resultat.returncode != 0:
        print(f"  échec : {' '.join(arguments)}\n  {resultat.stderr.strip()}", file=sys.stderr)
        sys.exit(1)


def poids(chemin: Path) -> str:
    return f"{chemin.stat().st_size / 1024:.0f} Ko"


def preparer_logo(source: Path) -> None:
    """Reconstruit le logo en rouge de marque sur fond transparent."""
    masque = SORTIE / "_masque.png"

    # Luminance inversée : l'encre devient blanche, le fond noir. Le -level
    # écrase les artefacts JPEG du fond et sature le coeur des lettres, le
    # -trim recadre sur le dessin une fois le fond vraiment noir.
    executer([
        "convert", str(source),
        "-colorspace", "Gray",
        "-negate",
        "-level", LOGO_PLAGE,
        "-trim", "+repage",
        str(masque),
    ])

    for nom, largeur in (("logo-2x.png", 880), ("logo.png", 440), ("favicon.png", 64)):
        cible = SORTIE / nom
        executer([
            "convert",
            str(masque), "-fill", LOGO_ROUGE, "-colorize", "100",
            str(masque), "-alpha", "off",
            "-compose", "CopyOpacity", "-composite",
            "-resize", f"{largeur}x",
            "-strip",
            "-define", "png:compression-level=9",
            str(cible),
        ])
        print(f"  {nom:<16} {largeur:>4} px   {poids(cible)}")

    masque.unlink()


def preparer_photo(source: Path) -> None:
    """Sort la photo en WebP et en JPEG, à trois largeurs."""
    for largeur in SALLE_LARGEURS:
        for extension, qualite in (("jpg", "80"), ("webp", "72")):
            cible = SORTIE / f"salle-{largeur}.{extension}"
            arguments = [
                "convert", str(source),
                "-strip",
                "-resize", f"{largeur}x",
                "-quality", qualite,
            ]

            if extension == "webp":
                # method=6 : l'encodeur cherche plus longtemps. On ne paie ce
                # temps qu'une fois, à la préparation.
                arguments += ["-define", "webp:method=6"]
            else:
                # Un JPEG progressif s'affiche par passes plutôt que ligne à
                # ligne : moins désagréable sur une connexion lente.
                arguments += ["-interlace", "Plane"]

            executer(arguments + [str(cible)])
            print(f"  salle-{largeur}.{extension:<5} {largeur:>4} px   {poids(cible)}")


def main() -> None:
    if not SOURCES.is_dir():
        print(f"Dossier introuvable : {SOURCES}", file=sys.stderr)
        sys.exit(1)

    if shutil.which("convert") is None:
        print("ImageMagick (convert) est introuvable.", file=sys.stderr)
        sys.exit(1)

    SORTIE.mkdir(parents=True, exist_ok=True)

    logo = SOURCES / "logo.jpg"
    photo = SOURCES / "bouillon.jpg"

    if logo.is_file():
        print("Logo")
        preparer_logo(logo)

    if photo.is_file():
        print("\nPhoto de salle")
        preparer_photo(photo)

    total = sum(f.stat().st_size for f in SORTIE.iterdir() if f.is_file())
    print(f"\n{len(list(SORTIE.iterdir()))} fichiers, {total / 1024:.0f} Ko au total dans public/img/")


if __name__ == "__main__":
    main()
