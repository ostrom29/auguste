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

# Le fond de la page, pour composer l'image de partage dessus.
CREME = "#FBF7F0"

# Ce qui est plus clair que le haut de la plage devient transparent, ce qui est
# plus sombre que le bas devient opaque.
#
# Le seuil bas est haut parce que le fond du JPEG fourni n'est pas blanc : ses
# coins sont à rgb(205,205,205). Un seuil plus doux laisserait ce gris dans le
# masque et empêcherait le recadrage.
LOGO_PLAGE = "30%,50%"

# Largeurs et proportion de la bannière. Doivent rester d'accord avec
# SALLE_LARGEURS et SALLE_RATIO dans src/lib/rendu.php.
SALLE_LARGEURS = (420, 720, 1040)

# 2:1. La salle est tout en longueur : un cadre allongé l'épouse, alors qu'un
# 3:2 empilait beaucoup de sol et repoussait le contenu vers le bas. Un 21:9,
# essayé aussi, coupait les suspensions et réduisait la scène à une tranche.
SALLE_RATIO = 2.0

# Quelle part du surplus de hauteur on retire par le haut. On garde les
# globes et la banquette, on rogne le sol, qui n'apporte rien.
# Exprimé en proportion et non en pixels, pour tenir avec une autre photo.
SALLE_CADRAGE = 0.45


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


def dimensions(chemin: Path) -> tuple[int, int]:
    resultat = subprocess.run(
        ["identify", "-format", "%w %h", str(chemin)],
        capture_output=True, text=True, check=True,
    )
    largeur, hauteur = resultat.stdout.split()
    return int(largeur), int(hauteur)


def preparer_photo(source: Path) -> None:
    """Sort la photo en WebP et en JPEG, à trois largeurs, recadrée en 3:2."""
    source_l, source_h = dimensions(source)

    for largeur in SALLE_LARGEURS:
        hauteur = round(largeur / SALLE_RATIO)

        # Hauteur obtenue une fois l'image mise à la largeur voulue, puis part
        # du surplus retirée par le haut.
        apres = round(source_h * largeur / source_l)
        decalage = max(0, round((apres - hauteur) * SALLE_CADRAGE))

        for extension, qualite in (("jpg", "80"), ("webp", "72")):
            cible = SORTIE / f"salle-{largeur}.{extension}"
            arguments = [
                "convert", str(source),
                "-strip",
                "-resize", f"{largeur}x",
                "-crop", f"{largeur}x{hauteur}+0+{decalage}", "+repage",
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
            print(f"  salle-{largeur}.{extension:<5} {largeur}x{hauteur:<4}   {poids(cible)}")


def preparer_ornement(source: Path) -> None:
    """Extrait la fioriture qui coiffe l'enseigne, pour la réutiliser seule.

    Elle sert de séparation entre les grandes sections. Reprendre un motif que
    le logo contient déjà lie la page à l'identité sans rien inventer — et ne
    coûte que quelques kilo-octets, puisque le dessin existe.
    """
    masque = SORTIE / "_orn.png"

    # La bande supérieure du dessin, avant le mot « CHEZ ». Le -trim resserre
    # ensuite sur le motif lui-même, donc le pourcentage n'a pas à être exact.
    executer([
        "convert", str(source),
        "-colorspace", "Gray", "-negate", "-level", LOGO_PLAGE,
        "-trim", "+repage",
        "-gravity", "North", "-crop", "100%x18%+0+0", "+repage",
        "-trim", "+repage",
        str(masque),
    ])

    for nom, largeur in (("ornement-2x.png", 840), ("ornement.png", 420)):
        cible = SORTIE / nom
        executer([
            "convert",
            str(masque), "-fill", LOGO_ROUGE, "-colorize", "100",
            str(masque), "-alpha", "off",
            "-compose", "CopyOpacity", "-composite",
            "-resize", f"{largeur}x",
            "-strip", "-define", "png:compression-level=9",
            str(cible),
        ])
        print(f"  {nom:<16} {largeur:>4} px   {poids(cible)}")

    masque.unlink()


def preparer_partage(photo: Path | None) -> None:
    """L'image que WhatsApp, Facebook et Slack affichent avec le lien.

    1200x630 est le format attendu partout. Avec une photo du lieu, c'est la
    salle assombrie et l'enseigne posée dessus en blanc — bien plus engageant
    dans un fil de discussion. Sans photo, l'enseigne sur le crème de la page.
    """
    cible = SORTIE / "partage.jpg"

    if photo is None:
        executer([
            "convert",
            "-size", "1200x630", f"xc:{CREME}",
            str(SORTIE / "logo-2x.png"), "-gravity", "center",
            "-geometry", "+0-30", "-composite",
            str(SORTIE / "ornement-2x.png"), "-gravity", "center",
            "-geometry", "+0+220", "-composite",
            "-strip", "-quality", "88", "-interlace", "Plane",
            str(cible),
        ])
        print(f"  partage.jpg      1200x630   {poids(cible)}  (enseigne seule)")
        return

    blanc = SORTIE / "_logo-blanc.png"

    # Le logo est rouge sur transparent : sur une photo, il faut du blanc.
    executer([
        "convert", str(SORTIE / "logo-2x.png"),
        "-fill", "white", "-colorize", "100",
        "-resize", "520x",
        str(blanc),
    ])

    executer([
        "convert", str(photo),
        "-resize", "1200x630^",
        "-gravity", "center", "-extent", "1200x630",
        # Assombrir pour que le logo blanc se détache sans caisson derrière.
        "-brightness-contrast", "-22x6",
        str(blanc), "-gravity", "center", "-composite",
        "-strip", "-quality", "84", "-interlace", "Plane",
        str(cible),
    ])

    blanc.unlink()
    print(f"  partage.jpg      1200x630   {poids(cible)}  (salle + enseigne)")


def main() -> None:
    if not SOURCES.is_dir():
        print(f"Dossier introuvable : {SOURCES}", file=sys.stderr)
        sys.exit(1)

    if shutil.which("convert") is None:
        print("ImageMagick (convert) est introuvable.", file=sys.stderr)
        sys.exit(1)

    SORTIE.mkdir(parents=True, exist_ok=True)

    logo = SOURCES / "logo.jpg"

    # La photo de la salle, sous l'un des noms acceptés. Son absence n'est pas
    # une erreur : le site affiche alors son bandeau de prix.
    photo = next(
        (SOURCES / n for n in ("salle.jpg", "salle.png", "salle.jpeg", "salle.webp")
         if (SOURCES / n).is_file()),
        SOURCES / "salle.jpg",
    )

    if logo.is_file():
        print("Logo")
        preparer_logo(logo)
        print("\nOrnement")
        preparer_ornement(logo)

    # La photo du lieu n'est traitée que si elle existe vraiment. Tant qu'on
    # n'en a pas, le site s'en passe : mieux vaut pas d'image que celle d'une
    # autre maison.
    if photo.is_file():
        print("\nPhoto de salle")
        preparer_photo(photo)

    if logo.is_file():
        print("\nImage de partage")
        preparer_partage(photo if photo.is_file() else None)

    total = sum(f.stat().st_size for f in SORTIE.iterdir() if f.is_file())
    print(f"\n{len(list(SORTIE.iterdir()))} fichiers, {total / 1024:.0f} Ko au total dans public/img/")


if __name__ == "__main__":
    main()
