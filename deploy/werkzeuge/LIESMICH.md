# Einmalwerkzeuge

Zwei kleine Skripte für den Fall, dass in einer **abgeschlossenen** Einheit ein
Häkchen fehlt, das dort hingehört. Über die Oberfläche ist das nicht zu
reparieren: `api/log.php` schreibt ausschließlich in eine **laufende** Einheit,
und `history.php` kann eine Einheit nur löschen, nicht bearbeiten.

**Wie es dazu kommen konnte** steht in `CLAUDE.md`, Fallstrick 13: Bis `1.4.4`
setzte eine fachliche Ablehnung das Häkchen einer abgehakten Übung
fälschlich zurück; wurden danach Sätze gespeichert, ging `done = 0` an den
Server. Der Fehler ist behoben — diese Skripte sind für den Altbestand.

**Sie gehören NICHT ins Image.** `deploy/` steht nicht in der Positivliste von
`paket_bauen.sh`; die Dateien werden bei Bedarf von Hand in den Container
kopiert oder ihr Inhalt in die Container-Konsole eingefügt.

## Vorher: Sicherung anlegen

Über die Wartungsseite. Beide Skripte fassen die Live-Datenbank an — das zweite
schreibend.

## 1. Nachsehen (ändert nichts)

```bash
php /var/www/html/deploy/werkzeuge/einheit_pruefen.php 2026-08-29
```

Das Argument ist ein Datumspräfix (`2026-08-29` oder `2026-08`). Ausgegeben
werden alle Einheiten dieses Zeitraums mit „x/n" und ihren Positionen. Eine
Zeile mit `done=0`, die trotzdem Gewicht oder Sätze trägt, ist markiert — das
ist der Fall, um den es geht.

## 2. Häkchen setzen (schreibt)

```bash
php /var/www/html/deploy/werkzeuge/haken_setzen.php <einheit-id> [<position-id>]
```

Ohne Positionsangabe wird die **ganze Einheit** berichtigt, mit einer nur diese
eine Position. Die Zahlen stammen aus der Ausgabe von Schritt 1.

Eine Zeile wird nur angefasst, wenn **alle drei** Bedingungen stimmen:

1. `done = 0`
2. sie trägt **Gewicht oder Sätze** — eine Zeile ganz ohne Werte kann eine
   bewusst angefangene und nicht beendete Übung sein, und daraus ein „erledigt"
   zu machen hieße, eine Aussage zu erfinden;
3. sie hängt noch an einer **Planposition**.

**Die dritte ist die unscheinbarste und die wichtigste.** Wird eine Übung nach
dem Training aus dem Plan genommen, setzt `ON DELETE SET NULL` ihre
`plan_exercise_id` auf `NULL` (§4.1). Die Protokollzeile bleibt und zählt in „x"
mit, während „n" nur noch die verbliebenen Planpositionen zählt — ein Häkchen
dort ergäbe „7/6". Genau davor warnt Fallstrick 2. In der Diagnose erkennt man
solche Zeilen an der **leeren Positionsnummer**.

Beide Fälle werden als *übersprungen* gemeldet, mit Grund.

**Es setzt nur `done = 1`.** Sätze, Gewicht und Zeitstempel bleiben unberührt —
sie waren nie weg. Mehrfaches Ausführen ist harmlos.
