# Lokaler Prüfstand vom 16. Oktober 2026

Basis: Branch `feat/slice-07-bcm-bia-exercises`, Commit `61e16a4` plus die lokalen Korrekturen dieses Durchlaufs.

## Korrekturen

- BcmExercise normalisiert Datenbank-Zeitwerte auf UTC. Derselbe absolute Zeitpunkt mit anderem Offset erzeugt dadurch keine unnötige Änderung der Sperrversion.
- Zwei Risiko-Migrationstests bauen die später hinzugekommenen BCM-Abhängigkeiten in umgekehrter Reihenfolge ab und anschließend wieder auf.
- Die drei zuvor fehlschlagenden Tests wurden im vollständigen Testlauf reproduziert. Anschließend bestanden 51 gezielte Tests (605 Assertions), einschließlich der BCM-End-to-End-Zeitprüfungen. Ein unabhängiger Review der drei geänderten Dateien fand keine weiteren konkreten Fehler.

## Frisch ausgeführte Prüfungen

- PHP-Formatprüfung: 500 Dateien erfolgreich.
- PHPStan mit 512 MB Speicherlimit: keine Fehler. Der erste Composer-Gesamtlauf brach mit dem Standardlimit von 128 MB ab; die dokumentierten Einzelprüfungen wurden danach vollständig ausgeführt.
- Backend: 844 Tests erfolgreich, 5.257 Assertions, 70,76 Sekunden.
- Frontend `npm run check`, `npm run types:check`: erfolgreich.
- Risiko-, BIA- und Plan-/Übungs-State-Tests: erfolgreich.
- Produktionsbuild mit temporärer PHP-Weiterleitung in Docker: erfolgreich.
- Docker-Compose-Konfiguration und `git diff --check`: erfolgreich.
- Lokale Loginseite: HTTP 200 unter http://localhost:8080/login.
- Anwendungsmigrationen einschließlich Slice 7 sind eingespielt.

Die Backendtests liefen ausschließlich gegen die neu angelegte separate Datenbank `isms_verify_20261016`. Die laufende Anwendungsdatenbank wurde nicht zurückgesetzt. App, Webserver und Datenbank wurden für den lokalen Browser-Test gestartet.

## Abschlussarbeiten vom 18. Oktober 2026

Der vollständige Review des Slice-7-Bereichs (Basis `277f2d5`) ist erfolgt. Zwei wichtige Befunde wurden mit zunächst fehlschlagenden Regressionstests korrigiert:

- Browserformulare stellen Termine in der lokalen Browserzeit dar und senden absolute Zeitpunkte. Unveränderte Termine bewahren Sekunden und die genaue Zeit während der doppelten Herbststunde. Die Regression umfasst Sommer/Winter in Berlin und eine abweichende Browserzone (New York).
- Folgeentwürfe dürfen historische inaktive Prozessverknüpfungen übernehmen, damit diese entfernt/ersetzt werden können. Projektzugehörigkeit bleibt geprüft; neue inaktive Verknüpfungen und Freigaben mit inaktiven Prozessen bleiben gesperrt. Regression: Folgeentwurf → gesperrte Freigabe → Prozess ersetzen → freigeben; alte Revision unverändert.

44 gezielte Plan-Tests mit 70 Assertions bestanden. Der anschließende vollständige Composer-Lauf vom 16. Oktober bestand mit 845 Tests und 5.262 Assertions. Das PHPStan-Speicherlimit von 512 MB ist nun im Composer-Prüfbefehl festgelegt. Die drei Frontend-State-Tests sind in GitHub Actions aufgenommen.

Der separate Migrationsabschluss lief gegen `isms_migrations_20261016`: leere Datenbank vollständig migriert, dreifaches erfolgreiches Seeding (zweimal vor, einmal nach Rollback), acht Schritte zurückgerollt und erneut migriert. Die sieben BCM-Migrationen plus die Katalog-Importmigration wurden dabei geprüft. Der separate Test zur verweigerten Rücknahme bei vorhandenen BCM-Feststellungen bestand mit 3 Assertions; Daten und Schema blieben erhalten.

Entscheidung: Nur beim Erstellen/Wiederöffnen eines Folgeentwurfs sind historische inaktive Prozesse zulässig. Speichern und Freigabe behalten die strengere Prüfung. Falls dieses Verhalten zu restriktiv ist, muss beim Bearbeiten zuerst der inaktive Prozess entfernt werden; eine stillschweigende Freigabe ist ausgeschlossen.

Nicht blockierender Reviewpunkt: Die Seitennavigation innerhalb von Quellen/Historie bewahrt ungespeicherte Formulare noch nicht ausdrücklich. Vor dem Wechsel einer solchen Seite speichern. Dieser Punkt bleibt als Folgeaufgabe dokumentiert.

Am 18. Oktober wurde der unveränderte Korrekturstand erneut mit `composer test` vollständig geprüft: 845 Tests, 5.262 Assertions, 67,08 Sekunden, Exitcode 0. Frontendformatierung (92 Dateien), Lint (90 Dateien), TypeScript und alle drei State-Testskripte bestanden erneut. Der Produktionsbuild vom Reviewkorrekturstand am 16. Oktober bestand; seitdem wurden keine weiteren Produktdateien geändert.

## Noch offen

- Tatsächliche Microsoft-Anmeldung mit dem Benutzer und anschließender manueller Browser-Test. Das Vorhandensein der Konfigurationswerte bestätigt noch keine erfolgreiche Anmeldung.
- Abschluss über Pull Request und Integration. Dieser Durchlauf hat weder gepusht noch gemergt.
