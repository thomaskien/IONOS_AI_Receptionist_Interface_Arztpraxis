# Uebergabe App

Stand: 2026-06-25

## Grundregel

Fuer die Weiterarbeit an `telepraxis-app.php` gilt ausdruecklich: immer Rueckfrage statt Improvisation.

Nur auf der vom Nutzer bestaetigten Ausgangsdatei arbeiten. Wenn die vorliegende Datei nicht exakt zum besprochenen Stand passt, nicht rekonstruieren und nicht raten, sondern sofort nachfragen.

## Verbindliche Arbeitsregeln

- Immer nur auf der vom Nutzer ausdruecklich bestaetigten Ausgangsdatei aufbauen.
- Wenn Dateiinhalt, Version oder Changelog nicht zum besprochenen Stand passen, sofort Rueckfrage halten.
- Keine selbststaendigen Zusatzaenderungen.
- Nur genau das aendern, was besprochen und freigegeben wurde.
- Changelog immer fortfuehren und niemals entfernen.
- Neue Version nur nach ausdruecklicher Freigabe des Nutzers ausgeben.
- Ausgabe immer als `telepraxis-app.php`, sofern nicht ausdruecklich anders gewuenscht.
- Bei Unsicherheit lieber Rueckfrage als Annahme.
- Funktionsverlust gegenueber der bestaetigten Basisdatei vermeiden.

Vor Ausgabe pruefen:

- PHP-Syntax,
- eingebettetes JavaScript,
- zuvor bestaetigte Funktionen,
- Header,
- Polling,
- Kommentare,
- Tabellen,
- Papierkorb.

## Projektkontext

`telepraxis-app.php` ist eine Ein-Datei-Webapp in PHP.

- Datenbasis: JSON-Dateien aus `./inbox`
- Statussystem: Neu, In Bearbeitung, Abgeschlossen, Papierkorb/Soft-Delete
- Polling-Refresh aktiv
- Sichtbare UI-Bezeichnung: Platz
- Kommentare in In Bearbeitung
- Druck- und Zwischenablage-Funktion fuer Karten in Bearbeitung
- Karten- und Tabellenansicht fuer mehrere Bereiche
- Admin-Bereich, Papierkorb und Endloeschung
- Responsive Kopfzeile mit Hamburger-Menue

## Funktionen, die erhalten bleiben muessen

Allgemein:

- JSON-Einlesen aus `./inbox`
- Statuswechsel Neu, In Bearbeitung, Abgeschlossen
- Soft-Delete in Papierkorb
- Restore und Purge im Papierkorb
- Dringend-Markierung
- Polling
- Browsertitel mit Anzahl neuer Vorgaenge
- Benachrichtigungston

Platz:

- Sichtbare UI-Bezeichnung `Platz`
- interne Variablen- und Feldnamen duerfen aus Kompatibilitaetsgruenden bleiben
- Platz kann per URL gesetzt werden: `?arbeitsplatz=<wert>`
- Bookmark-Link-Funktion existiert
- `Oeffnen` erscheint nach Erzeugen des Bookmark-Links

In Bearbeitung:

- Links nur eigene Vorgaenge des gesetzten Platzes
- Kommentar-Button
- dreizeiliges Kommentarfeld
- Speicherung im JSON mit `text`, `created_at`, `workplace`
- beliebig viele Kommentare
- Anzeige unter `Uebermittelte Telefonnummer`
- Fokus/Cursor im Kommentarfeld soll Polling ueberstehen
- Druckfunktion
- `Karte in die Zwischenablage` als reiner Text
- Symbolbuttons fuer Drucken und Zwischenablage
- Name linksbuendig

Tabellenansichten:

- Bereiche Neu, Abgeschlossen, Papierkorb
- Umschaltbar zwischen Karten und Tabelle
- Tabellenzeilen umrandet
- Farblogik analog Karten
- Checkboxen und Sammelaktionen je Bereich
- In der Neu-Tabelle ist die Vorschau auf 3 Zeilen begrenzt; voller Text nur im Tooltip

Papierkorb:

- Pfeilknopf fuer Ein-/Ausblenden
- Geschlossen: Button unten mittig als Overlay ohne Platzreservierung, Pfeil nach oben
- Geoeffnet: derselbe Button in der Papierkorb-Titelleiste, Pfeil nach unten
- Checkbox im Hamburger-Menue fuer Papierkorb bleibt zusaetzlich bestehen
- `Papierkorb anzeigen` ist nur bei aktivem Admin im Hamburger-Menue sichtbar

## Bestaetigte UI- und Verhaltensdetails

Kopfzeile:

- Links Titelblock
- Rechts Hamburger-Menue
- Linker Titelblock bleibt immer sichtbar
- Hamburger-Menue rechts bleibt immer sichtbar
- Ausblendbar bei Platzmangel sind nur Mittelteil mit Statuschips und `Abgeschlossen anzeigen`

Typografie Kopfzeile:

- Nur `telepraxis-app` hervorgehoben/fett
- Version und `von Dr. Thomas Kienzle` dezenter und nicht fett

Platz-Placeholder:

- Beispieltext: `"Julia", "anm-li" oder "Dr. Meier"`

Wenn kein Platz gesetzt ist:

- Zusaetzliche Platz-Eingabe als zweite Zeile ueber dem Inhalt

Hamburger-Menue:

- Platz-Eingabe
- Speichern
- Bookmark-Link
- Oeffnen
- Admin
- Benachrichtigungs-Ton
- Abgeschlossen anzeigen
- Papierkorb anzeigen, nur bei aktivem Admin
- Zaehler

Loeschlogik:

- In Bearbeitung: kein Loeschknopf mehr
- Abgeschlossen, Einzelaktion: Rueckfrage `Eintrag in den Papierkorb verschieben?`
- Abgeschlossen, Sammelaktion: Rueckfrage `Ausgewaehlte Eintraege in den Papierkorb verschieben?`

## Historie und Fallstricke

Es gab mehrfach versehentliche Rueckschritte, weil auf falschen Dateistaenden weitergearbeitet wurde.

- Nicht davon ausgehen, dass eine Datei mit Versionsnummer X den erwarteten Inhalt hat.
- Immer pruefen, ob die vom Nutzer bestaetigte Datei wirklich den besprochenen Stand enthaelt.
- Features nicht nach Gefuehl aus aelteren Versionen zusammensetzen.
- Bei Abweichungen sofort nachfragen.

## Letzter Arbeitsstand

Weiterarbeit soll auf der zuletzt vom Nutzer bestaetigten Datei erfolgen. Der Nutzer hat die Dateien lokal vorliegen.

Vor jeder Aenderung kurz bestaetigen:

- Ist das wirklich die bestaetigte Ausgangsdatei?
- Welche Aenderungen sind exakt freigegeben?

Der zuletzt bestaetigte UI-Fix war sinngemaess:

- linker Titelblock immer sichtbar,
- Hamburger-Menue immer sichtbar,
- ausblendbar nur Mittelteil plus `Abgeschlossen anzeigen`.

## Empfohlenes Vorgehen

Vor jeder neuen Aenderung:

- Ausgangsdatei pruefen.
- Version und Changelog pruefen.
- Besprochene Aenderungen in eigenen Worten knapp zusammenfassen.
- Nur nach Bestaetigung aendern.
- Nach Aenderung PHP linten.
- Eingebettetes JavaScript und regressionsverdaechtige Stellen pruefen.
