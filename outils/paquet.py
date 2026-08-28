#!/usr/bin/env python3
"""
Prépare les deux archives de déploiement, une par destination sur le serveur.

    python3 outils/paquet.py

Produit dans dist/ :

    1-auguste.zip      à extraire dans ~/          → ~/auguste/
    2-public_html.zip  à extraire dans ~/public_html/

Le Gestionnaire de fichiers cPanel n'envoie que des fichiers, jamais des
dossiers : on envoie donc une archive et on l'extrait sur place.

Ceci est un outil de la machine du développeur, pas un build step du site :
le site reste du HTML et du CSS écrits à la main, générés par PHP. Rien ici
n'est nécessaire pour servir la page, ni installé sur l'hébergement.

config.php n'est jamais empaqueté : il contient le secret de publication et
un chemin de sortie propres à chaque machine. Il se crée sur le serveur, à
partir de config.example.php qui, lui, est dans l'archive.
"""

import shutil
import zipfile
from pathlib import Path

RACINE = Path(__file__).resolve().parent.parent
DIST = RACINE / "dist"


# verif.sh a besoin des fixtures et de l'arborescence du dépôt : sur le serveur
# il ne saurait qu'échouer. C'est un outil de développement, il reste ici.
EXCLUS = {"verif.sh"}


def ajouter(archive: zipfile.ZipFile, source: Path, destination: str) -> None:
    """Ajoute un fichier ou tout un dossier, en conservant l'arborescence."""
    if source.is_file():
        archive.write(source, destination)
        return

    for chemin in sorted(source.rglob("*")):
        if chemin.is_file() and chemin.name not in EXCLUS:
            archive.write(chemin, f"{destination}/{chemin.relative_to(source)}")


def main() -> None:
    shutil.rmtree(DIST, ignore_errors=True)
    DIST.mkdir(parents=True)

    # Ce qui doit rester hors du web : le générateur et sa configuration.
    with zipfile.ZipFile(DIST / "1-auguste.zip", "w", zipfile.ZIP_DEFLATED) as z:
        ajouter(z, RACINE / "src", "auguste/src")
        ajouter(z, RACINE / "config.example.php", "auguste/config.example.php")
        # Dossier vide mais nécessaire : le cache des CSV téléchargés.
        z.writestr(zipfile.ZipInfo("auguste/cache/"), b"")

    # Ce qu'Apache sert.
    with zipfile.ZipFile(DIST / "2-public_html.zip", "w", zipfile.ZIP_DEFLATED) as z:
        ajouter(z, RACINE / "public" / "publier.php", "publier.php")
        ajouter(z, RACINE / "public" / "style.css", "style.css")

    for archive in sorted(DIST.iterdir()):
        print(f"\n== {archive.name} ({archive.stat().st_size} octets) ==")
        with zipfile.ZipFile(archive) as z:
            for nom in z.namelist():
                print(f"   {nom}")


if __name__ == "__main__":
    main()
