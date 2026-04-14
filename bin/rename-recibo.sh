#!/bin/bash

# This script comes with ABSOLUTELY NO WARRANTY, use at own risk
# Copyright (C) 2024 Martin Ales <marto.ales@gmail.com>
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
# General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program. If not, see <http://www.gnu.org/licenses/>.

shopt -s extglob
PREFIJO='30680248512_015_00002_00' # Prefijo para borrar del nombre de archivo
SUFIJO='.pdf' # Sufijo para borrar del nombre del archivo
BUSCAR='en concepto de' # Texto para buscar dentro del pdf
BORRAR1A='social ' # Para borrar del texto del pdf Linea 1
BORRAR1B='Social ' # Para borrar del texto del pdf Linea 1
BORRAR2='Familia ' # Para borrar del texto del pdf Linea 2

ARCHIVO=$1

RES=$(lesspipe "$ARCHIVO" | grep -m1 -A2 "$BUSCAR")
LIN1=$(echo "$RES" | awk 'NR==2')
LIN1=${LIN1//@("$BORRAR1A"|"$BORRAR1B")/}
LIN2=$(echo "$RES" | awk 'NR==3')
LIN2=${LIN2//"$BORRAR2"/}

ARCHIVO=$(basename "$ARCHIVO")
NRO="${ARCHIVO#"$PREFIJO"}"
NRO="${NRO%"$SUFIJO"}"
OUT=$(echo "${NRO} -${LIN1} -${LIN2}.pdf" | sed -e 's/[^A-Za-z0-9ñÑ°._-]/ /g')
echo "$OUT"

