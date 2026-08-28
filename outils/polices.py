#!/usr/bin/env python3
"""
Récupère EB Garamond depuis Google Fonts et l'héberge en local.

    python3 outils/polices.py

Écrit les .woff2 dans public/polices/ et affiche les règles @font-face à coller
dans public/style.css.

Pourquoi héberger plutôt que lier le CDN de Google : le site ne doit dépendre
d'aucun service extérieur pour s'afficher, la page ne fait alors aucune requête
vers un tiers, et rien de ce que fait le visiteur ne sort du serveur.

EB Garamond est sous SIL Open Font License : l'hébergement est autorisé.
"""

import re
import sys
import urllib.request
from pathlib import Path

RACINE = Path(__file__).resolve().parent.parent
SORTIE = RACINE / "public" / "polices"

CSS = (
    "https://fonts.googleapis.com/css2"
    "?family=EB+Garamond:ital,wght@0,400;0,600;1,400&display=swap"
)

# Un navigateur récent, sinon Google sert du woff1 pour compatibilité.
UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0 Safari/537.36"
)


def telecharger(url: str) -> bytes:
    requete = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(requete, timeout=30) as reponse:
        return reponse.read()


def main() -> None:
    SORTIE.mkdir(parents=True, exist_ok=True)
    feuille = telecharger(CSS).decode("utf-8")

    # Chaque @font-face est précédé d'un commentaire qui nomme son sous-ensemble.
    # On ne garde que « latin » : les cyrilliques et le grec n'ont rien à faire
    # sur la carte d'un bouillon parisien, et pèsent pour rien.
    blocs = re.findall(
        r"/\*\s*([\w-]+)\s*\*/\s*@font-face\s*\{(.*?)\}",
        feuille,
        re.DOTALL,
    )

    regles = []

    for sous_ensemble, corps in blocs:
        if sous_ensemble != "latin":
            continue

        style = re.search(r"font-style:\s*(\w+)", corps)
        url = re.search(r"url\((https://[^)]+\.woff2)\)", corps)
        plage = re.search(r"unicode-range:\s*([^;]+);", corps)

        if not (style and url):
            continue

        italique = style.group(1) == "italic"
        nom = f"eb-garamond-{'italique' if italique else 'normal'}.woff2"
        cible = SORTIE / nom

        cible.write_bytes(telecharger(url.group(1)))
        print(f"  {nom:<28} {cible.stat().st_size / 1024:.0f} Ko", file=sys.stderr)

        regles.append(
            "@font-face {\n"
            "  font-family: 'EB Garamond';\n"
            f"  font-style: {style.group(1)};\n"
            # La police est variable : une seule ressource couvre la plage.
            "  font-weight: 400 600;\n"
            "  font-display: swap;\n"
            f"  src: url('polices/{nom}') format('woff2');\n"
            + (f"  unicode-range: {plage.group(1).strip()};\n" if plage else "")
            + "}"
        )

    if not regles:
        print("Aucun sous-ensemble latin trouvé.", file=sys.stderr)
        sys.exit(1)

    print("\n".join(regles))


if __name__ == "__main__":
    main()
