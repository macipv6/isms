# Slice 7 — BIA, Notfallplanung und Übungen

Status: Vom Nutzer freigegeben; Implementierung nach Planprüfung.

## Ziel und bestätigte Entscheidungen

Interne ISMS-Berater sollen Ausfallfolgen bewerten, Wiederanlauf planen und
Ergebnisse durch Übungen nachweisen können. Das Modul ist in jedem ISMS-Projekt
verfügbar; `Aufbau-BCMS` ist keine Zugangsvoraussetzung.

Bestätigt sind drei getrennte, relational verknüpfte Bereiche: BIA,
Notfallplanung und Übungen. Die BIA verwendet standardmäßig 1, 4, 8, 24 und
72 Stunden sowie 7 Tage. Zeitstufen sind je Projekt anpassbar und versioniert;
bestehende Bewertungen bleiben erhalten und werden zur erneuten Prüfung markiert.
Es gibt keine automatische Umrechnung oder Löschung.

Die vier Standardbereiche sind finanzielle Schäden, Betriebsunterbrechung,
rechtliche/vertragliche Folgen und Reputation. Pro Bereich und Zeitstufe wird
von gering bis kritisch bewertet und begründet. RTO muss unter der maximal
tolerierbaren Ausfallzeit liegen; RPO beschreibt den tolerierbaren Datenverlust.
Abhängigkeiten werden auf Zielkonflikte geprüft. Unvollständige Entwürfe sind
erlaubt; Freigaben benötigen vollständige Angaben und behandelte Konflikte.

Notfallplanung enthält Strategien, Wiederanlaufschritte, Verantwortliche und
Stellvertretung; Kontakte dürfen ohne Benutzerkonto erfasst werden. Übungen
enthalten Szenario, Termin, Beteiligte, Soll-/Ist-Ergebnisse und Nachweise.
Abweichungen können ausdrücklich als Feststellungen mit Maßnahmen übernommen
werden. Freigaben bleiben versioniert; relevante Änderungen lösen Prüfbedarf aus.
Berechtigungen, Mandantentrennung und Audit folgen den vorhandenen Modulen.
Gezielte Tests während der Umsetzung und eine vollständige Abschlussprüfung
begrenzen unnötige Wiederholung.

Die folgenden Detailregeln konkretisieren diese Entscheidungen und stehen mit
dieser schriftlichen Spezifikation zur Prüfung. Das Modul behauptet keine
vollständige Normkonformität oder Zertifizierbarkeit.

## Umfang und Grenzen

Verwendet werden die vorhandenen Prozess-/Assetregister, Abhängigkeiten,
Nachweise, Feststellungen und Maßnahmen. Risiken können lesbar referenziert
werden; BCM schreibt keine Risikoakzeptanz. Umsetzung in drei abhängigen
Schritten: zuerst BIA/Konsistenz, danach Notfallplanung, danach Übungen.

Nicht enthalten: Alarmierung, operative Incident-Steuerung, externe Logins,
Kalendersynchronisation, neue Uploadformate, BCM-CSV-Import, KI und PDF/DOCX-
Generierung. Dokumentrendering folgt in Slice 9. Bestehende CSV-Importe bleiben
verfügbar. Ein einziger Großformular-Workflow bzw. reine Dokumentvorlagen werden
zugunsten eigener Services und überprüfbarer Verknüpfungen nicht verwendet.

## BIA-Konfiguration und Bewertungen

`BiaConfigurationVersion` gehört einem Projekt und hält geordnete Zeitstufen
als ganze Minuten. Standardwerte: 60, 240, 480, 1440, 4320, 10080. Anpassungen
erlauben 2–12 verschiedene, strikt steigende positive Werte bis 525600 Minuten.
Die vier Bereiche und Stufen gering=1, mittel=2, hoch=3, kritisch=4 bleiben fest;
anpassbare Bereiche, Geldschwellen und Skalen sind außerhalb dieses Slices.

Aktivierte Konfigurationen sind unveränderlich. Änderungen erstellen eine neue,
ausdrücklich aktivierte Version. Vorhandene BIA-Versionen behalten ihre Quellen
und erhalten Prüfbedarf. Ein Folgeentwurf verwendet die neue Konfiguration:
exakt identische Zeitstufen dürfen als überprüfbare Entwurfswerte kopiert werden,
neue Zeitstufen bleiben leer; keine Interpolation.

`BusinessImpactAnalysis` gehört einem Prozess. `BiaRevision` hält Konfiguration,
Bewertungen, Ziele, Verantwortlichkeit und Freigabemetadaten. Pro Prozess gibt es
maximal einen bearbeitbaren Entwurf. `BiaImpact` ist pro Revision/Bereich/Zeitstufe
eindeutig. Inaktive Prozesse können keine neuen freigegebenen Bewertungen erhalten.

Freigabe benötigt alle Bewertungen und Begründungen, Verantwortlichen, maximale
tolerierbare Ausfallzeit (MTPD), RTO und Zielbegründung. RPO ist entweder eine
nichtnegative Minutenanzahl oder ausdrücklich mit Begründung nicht anwendbar.
RTO/MTPD sind positive Minutenwerte; RTO muss strikt kleiner als MTPD sein.
Freie Ziele müssen nicht mit Zeitstufen übereinstimmen.

Die erste Zeitstufe mit einer kritischen Auswirkung wird als Orientierung
angezeigt, setzt aber keine Ziele automatisch. MTPD oberhalb dieser Zeitstufe
und abnehmende Auswirkungen im Zeitverlauf ergeben begründungspflichtige Hinweise.

## Wiederanlaufziele und Konsistenz

`AssetRecoveryObjectiveRevision` hält Asset-RTO/RPO und Begründung separat von
Schutzbedarf und Risiken. Fehlende Ziele gelten als unbekannt, nicht als null.
Prozessziele stammen aus aktueller freigegebener BIA.

Die vorhandene `DependencyGraph` liefert aktive direkte/transitive Projektziele.
Keine neue Graphstruktur. Alle Abhängigkeiten werden angezeigt. Harte Warnungen
gelten für Pfade mit ausschließlich kritischen Kanten; mindestens eine
unterstützende Kante ergibt einen beratenden Hinweis. Mehrere Pfade dürfen
einen kritischen Pfad nicht hinter einem kürzeren unterstützenden verstecken.
Ein fokussierter BCM-Traversalservice darf erforderliche Pfadnachweise ergänzen,
ohne die vorhandene Graphsemantik zu verändern.

Warnungen: fehlende/prüfbedürftige kritische Ziele, Abhängigkeits-RTO größer als
Prozess-RTO, anwendbare Abhängigkeits-RPO größer als Prozess-RPO. Gleiche Werte
sind kein Konflikt; bei nicht anwendbarer RPO erfolgt kein numerischer Vergleich.
Dies ist ein Zielvergleich, keine Simulation serieller Wiederanlaufschritte.

RTO >= MTPD ist nicht übersteuerbar. Fachliche Warnungen können behoben oder
explizit begründet akzeptiert werden. `BcmConflictAcceptance` bindet Actor, Zeit,
Begründung und Fingerprint an Warnung, Pfad und Quellversionen. Geänderte Quellen
erfordern eine neue Akzeptanz; historische Entscheidungen bleiben erhalten.

## Notfallplanung

`ContinuityPlan` besitzt unveränderliche freigegebene `ContinuityPlanRevision`-
Versionen und höchstens einen Entwurf. Ein Plan kann mehrere Prozesse abdecken
und pinnt verwendete BIA-/Assetzielversionen. Optional werden projektinterne
Risikoanalysen referenziert; dies ersetzt keine Risikoentscheidung.

Erfasst werden Titel, Szenario, Aktivierungsbedingungen, Strategie, Minimalbetrieb,
Normalbetriebsrückkehr und geordnete Wiederanlaufschritte. Schritte halten
Anweisung, Verantwortlichkeit und optional erwartete Dauer. Rollen erfassen
verantwortlichen Namen, optionale E-Mail/Telefonnummer und Stellvertretung.
Kontakte erhalten weder Benutzerkonto noch Rechte.

Freigabe benötigt mindestens einen aktiven Prozess mit aktueller freigegebener
BIA, gültige Quellen, Strategie, Aktivierungsbedingungen, Minimalbetrieb,
Normalbetriebsrückkehr, mindestens einen Schritt und Verantwortlichen samt
Stellvertretung. Verknüpfte Nachweise müssen projektintern und verifiziert sein.
Unvollständige Pläne bleiben Entwürfe und behaupten keine Bereitschaft.

## Übungen und Feststellungsintegration

`BcmExercise` gehört einem Projekt und pinnt eine freigegebene Planrevision.
Entwurf/Planung enthalten Szenario, Übungsart (`tabletop`, `recovery_test`),
Termin, Leiter, Beteiligte und Kriterien mit erwartetem Ergebnis und optionaler
Dauer. Planung benötigt aktuelle freigegebene Planquellen und mindestens ein Kriterium.

Ablauf: Entwurf -> geplant -> durchgeführt -> geprüft/abgeschlossen. Vor Abschluss
ist eine begründete Absage möglich. Ergebnisse halten tatsächlichen Termin,
Beobachtungen, Ergebnis pro Kriterium (`passed`, `partial`, `failed`, `not_tested`),
Begründung und gegebenenfalls Ist-Dauer fest. Nicht getestet gilt nicht als bestanden.

Abschluss benötigt vollständige Ergebnisse, gültige Freigabe und explizite
Behandlung jeder Abweichung: Feststellungsvorschlag oder begründete Entscheidung,
keine Feststellung anzulegen. Abschluss bescheinigt Dokumentation, nicht
Fehlerfreiheit. Nachweise sind optional, aber sobald verknüpft bei Abschluss
projektintern und verifiziert. Übungen ersetzen keine Evidence-Verifikation.

Vorhandene Feststellungen sind fragegebunden. Die Erweiterung führt explizite
Herkunftstypen `assessment_question` und `bcm_exercise_result` ein. Altdaten erhalten
den ersten Typ. Herkunftsfelder müssen genau eine gültige Herkunft desselben
Projekts belegen; Übungen erhalten keine erfundene Bewertungsfrage. Pro Ergebnis
ist höchstens eine vorgeschlagene/akzeptierte Feststellung aktiv. Erstellung ist
explizit und wiederholungssicher. Akzeptanz/Ablehnung und mehrere Maßnahmen nutzen
weiter den vorhandenen Workflow. Frageprüfungen bleiben bei Assessment-Herkunft
erhalten. Listen, Policies, Audit und Risikoverknüpfungen dürfen keine Frage
für BCM-Herkünfte voraussetzen. Kein zweites unabhängiges Finding-Modul.

## Historie und Prüfbedarf

Freigegebene BIA-, Assetziel- und Planrevisionen sowie abgeschlossene Ergebnisse
werden nicht überschrieben; Änderungen erzeugen Nachfolger. Referenzen zeigen auf
die verwendete Revision. Selbstfreigabe berechtigter interner Personen ist erlaubt
gemäß der bisherigen einfachen Freigabepolitik. Actor/Zeit sind Pflicht; externe
Kontakte erteilen keine Systemfreigaben.

Aktualität (`current`, `review_required`) ist vom historischen Freigabestatus
getrennt. Relevante Änderungen: Konfigurationswechsel, maßgebliche Zielversionen,
Zieldeaktivierung, relevante Graphkanten und Entzug verwendeter Nachweisverifikation.
Abhängige Pläne erhalten Prüfbedarf. Geplante Übungen dürfen ohne Neubestätigung
nicht gestartet werden; durchgeführte Übungen behalten Ergebnisse und historische
Quellen. Späterer Prüfbedarf schreibt abgeschlossene Übungen nicht zurück auf offen.

Aktualität wird beim Lesen und vor Statusaktionen anhand aktueller Quellen
abgeglichen. Ein veraltetes Cacheflag darf keine Freigabe ermöglichen. Der
bestehende ISMS-Projektabschluss erhält keine pauschale neue Pflicht für BIA aller
Prozesse; BCM-Bereitschaft wird separat und transparent ausgewiesen.

## Architektur, Sicherheit und Oberfläche

Services unter `Services/Bcm` verantworten Konfiguration, BIA, Assetziele,
Konsistenz, Planung, Übungen, Aktualität und Freigaben. Relationale Tabellen
verwenden projektbezogene Fremdschlüssel, eindeutige Identitäten und Prüfungen
für Status-/Herkunftskonsistenz. Änderungen sind atomar samt Audit; Quellobjekte,
Actor und Projekt werden frisch geprüft und konsistent gesperrt.

Optimistische Revisionen verhindern verlorene Updates. Konflikte liefern 409
und erhalten Formulareingaben; Validierungsfehler deutsche Meldungen ohne
Teiländerung. Aktive interne Admins/Consultants erhalten Rechte gemäß vorhandenen
Projektpolicies. Nur aktive Kundenorganisationen und Draft/Active-Projekte sind
beschreibbar; abgeschlossene/archivierte Projekte bleiben lesbar. Kundenbenutzer,
deaktivierte Actors/Organisationen und Fremdprojekt-IDs sind ausgeschlossen.
Verschachtelte Fremdressourcen liefern 404. UI-Capabilities ersetzen keine Policies.

Audit protokolliert Projekt-/Objekt-/Versions-IDs, Status und geänderte Feldnamen,
keine Kontakte, Szenarien oder Freitexte. Evidence verbleibt privat und wird
ausschließlich durch vorhandene autorisierte Downloads ausgeliefert.

Projektnavigation: BIA, Notfallpläne, Übungen. Übersicht trennt fehlende Angaben,
Prüfbedarf, Konflikte und Plan-/Übungsstand. BIA zeigt Bereich/Zeit-Matrix, Ziele
und Konfliktquellen. Pläne zeigen Rollen, Schritte, Quellen und Historie.
Übungen zeigen Soll/Ist, Nachweise und explizite Feststellungsübernahme.
Große Listen sind paginiert; Schreibschutz und History sind verständlich sichtbar.

## Verifikation und Übergabe

Gezielte TDD-Tests: Konfigurationsversionen, Entwürfe/Freigaben, Zeit-/RPO-Grenzen,
kritische/unterstützende transitive Pfade, unbekannte Ziele, Akzeptanzfingerprints,
Aktualität und unveränderliche Historie. Negative Tests: Fremdprojekt-IDs,
veraltete Actors, gesperrte Projekte und fremde/unverifizierte Nachweise.
Integration: atomare Freigabe, konkurrierende Updates, Übung -> Feststellung ->
mehrere Maßnahmen sowie unveränderte Assessment-Feststellungen. Vue-Tests
fokussieren Eingabeerhalt, Konflikte, Schreibschutz und History.

Abschluss: gesamte PostgreSQL-Suite, Pint, PHPStan, Frontendlint/-format,
TypeScript und regulärer Wayfinder-Build. Reversible Migrationen werden nur auf
separater entbehrlicher Prüfdatenbank geprüft; Rollback darf bestehende Audit-
und History-Sicherheit nicht lockern. Code-/Security-Review vor dem PR.
Änderungen erfolgen im Feature-Branch, nicht direkt auf `main`. Kein automatischer Merge.
