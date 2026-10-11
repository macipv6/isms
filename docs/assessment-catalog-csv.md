# Assessment-Fragekatalog CSV

Interne aktive Administratoren einer aktiven internen Organisation öffnen `/assessment-catalogs`. CSV prüfen, die Vorschau kontrollieren und anschließend verbindlich bestätigen. Die serverseitige, verschlüsselte Vorschau gilt 30 Minuten, gehört ausschließlich dem hochladenden Benutzer und kann nur einmal bestätigt werden. Eine vorhandene Versionsbezeichnung wird niemals überschrieben. Alle Fragen, Kategorien, Optionen und Regeln werden atomar als neue veröffentlichte Version angelegt. Assessment-Snapshots bleiben erhalten.

Nur eigene oder rechtmäßig vorhandene Fragen hochladen. Die herunterladbare Vorlage enthält ein selbst erstelltes Formatbeispiel, keine offiziellen BSI-Texte.

UTF-8 mit optionalem BOM, Komma oder Semikolon, maximal 5 MiB und 10.000 Datensätze. Standard-CSV-Quoting: Felder mit Trennzeichen oder Zeilenumbrüchen in doppelte Anführungszeichen einschließen; enthaltene Anführungszeichen verdoppeln. Kopfzeile exakt:

```text
category_key,category_name,category_description,category_sort_order,question_key,title,question_text,help_text,answer_type,severity,evidence_expected,is_active,sort_order,options_json,rules_json
```

Pro Datensatz eine Frage. Wiederholte Kategorie-Schlüssel müssen dieselben Namen, Beschreibungen und Sortierungen haben. Frage-Schlüssel sind versionsweit eindeutig. Kategorie-Schlüssel maximal 64, Frage-Schlüssel maximal 128 Zeichen, jeweils Buchstaben/Ziffern/Punkt/Unterstrich/Bindestrich. Titel und Kategorienamen maximal 255, Beschreibungen/Fragentexte/Hilfen maximal 10.000 Zeichen. Sortierungen sind nichtnegative Ganzzahlen bis 2147483647. Schweregrad: `low`, `medium`, `high`, `critical`. Booleans: exakt `0` oder `1`. Antworttypen: `boolean`, `single_choice`, `multiple_choice`, `text`, `number`.

Optionen/Regeln sind JSON-Listen (auch leere Listen explizit als `[]`), maximal 100 Elemente pro Liste. Auswahlfragen benötigen Optionen; andere Antworttypen erlauben keine. Optionswerte innerhalb einer Frage eindeutig, maximal 64 Zeichen; Label maximal 255 Zeichen. Score ist Ganzzahl oder null, Sortierung eine nichtnegative Ganzzahl. Beispiel für `options_json` vor dem CSV-Quoting:

```json
[{"value":"annual","label":"Jährlich","score":1,"sort_order":10},{"value":"never","label":"Nie","score":0,"sort_order":20}]
```

Regeln stehen auf der auslösenden Frage. Zielschlüssel muss eine andere Frage dieser CSV sein. Operatoren: `equals`, `not_equals`, `contains`; Aktionen: `include`, `exclude`. `contains` nur bei Mehrfachauswahl. Erwartungswert: JSON-Boolean für boolean, Zahl für number, String für text, gültiger Optionswert als String für Einfachauswahl. Bei Mehrfachauswahl erwartet `contains` einen Optionswert, `equals`/`not_equals` eine Liste eindeutiger Optionswerte (Reihenfolge relevant wie beim bestehenden Regel-Auswerter). Beispiel einer Regel einer boolean-Frage:

```json
[{"target_question_key":"example.followup","operator":"equals","expected_value":true,"action":"include"}]
```

Das Beispiel benötigt zusätzlich eine Frage `example.followup` in der CSV. Unbekannte JSON-Felder, doppelte Regelidentitäten und Selbstbezüge sind verboten. Textfelder und Optionswerte/Labels dürfen nach führendem Leerraum nicht mit `=`, `+`, `-` oder `@` beginnen (Schutz vor Tabellenformeln). Die Vorschau zeigt höchstens 25 Fragen; validiert wird immer der gesamte Upload. Audit-Ereignisse enthalten ausschließlich IDs und Anzahl der Elemente, keine Fragen oder Texte.
