# Schutzbedarf und Risikomanagement

Interne Administratoren und Consultants erreichen die Arbeitsbereiche über die Projektnavigation. Abgeschlossene oder archivierte Projekte bleiben lesbar; fachliche Änderungen sind dort gesperrt.

## Projektablauf

1. Den Risikokontext mit veröffentlichten Methodik- und Gefährdungskatalogversionen initialisieren. Ein späterer Versionswechsel erfolgt ausdrücklich als Migration; historische Snapshots bleiben erhalten.
2. Vertraulichkeit, Integrität und Verfügbarkeit für Prozesse und Assets direkt bewerten und begründen. Der effektive Schutzbedarf berücksichtigt die aktiven Abhängigkeiten. Eine offene Herabstufung senkt den effektiven Wert erst nach gültiger Freigabe.
3. Verpflichtende Risikoauslöser und die Gefährdungsübersicht bearbeiten. Feststellungen können mit einem Prozess oder Asset verknüpft werden. Nicht anwendbare Gefährdungen benötigen eine Begründung; anwendbare Gefährdungen benötigen eine aktuelle Analyse.
4. Szenario und inhärente Bewertung erfassen. Die Risikokategorie wird aus der gepinnten Matrix berechnet.
5. Behandlung auswählen und ihre Voraussetzungen erfüllen. Reduktion benötigt abgeschlossene Maßnahmen, Übertragung verifizierte Nachweise und einen Vertragspartner, Vermeidung einen verifizierten Stilllegungsnachweis. Akzeptieren erlaubt keine behauptete Senkung von Häufigkeit oder Auswirkung.
6. Restrisiko bewerten. Hohe oder sehr hohe Restrisiken benötigen eine Managemententscheidung; interne Entscheidungen dürfen nicht vom letzten Bewerter stammen. Externe Entscheidungen werden mit separatem Recorder dokumentiert.
7. Den Zyklus abschließen. Alle verknüpften unterstützenden Nachweise müssen aktuell verifiziert sein. Materialänderungen erhalten die Historie und machen eine Neubewertung erforderlich. Ein Projektabschluss ist bei offenen Pflichtauslösern oder unvollständigen aktuellen Analysen gesperrt.

## Importe

Der Schutzbedarfsimport übernimmt ausschließlich direkte CIA-Werte und Begründungen bestehender aktiver Ziele. Vorschau, Ablauf, Versionskonflikte und atomare Bestätigung entsprechen den bestehenden Registerimporten. CSV-Daten werden nicht in URLs oder Audittexte übernommen.

Für Assessment-Fragekataloge steht internen Administratoren die Seite `/assessment-catalogs` mit herunterladbarer Vorlage zur Verfügung. Format, Optionen, Regeln und Grenzen sind in [assessment-catalog-csv.md](assessment-catalog-csv.md) dokumentiert. Die Bestätigung veröffentlicht eine neue Version; bestehende Assessments behalten ihre Snapshots. Der enthaltene Beispielkatalog ist intern BSI-orientiert und kein vollständiger offizieller BSI-Fragekatalog. Offizielle Fragen müssen als vorhandene CSV bereitgestellt werden.

## Lokale Abschlussprüfung

PHP 8.4+, PostgreSQL und die installierten Composer-Abhängigkeiten sind erforderlich. Im vorhandenen Docker-Setup:

```sh
docker compose up -d db
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress
docker compose run --rm app php artisan test --compact --no-ansi
npm run check
npm run types:check
npm run build
```

Der reguläre Frontendbuild benötigt PHP für Wayfinder. Ohne lokale PHP-Laufzeit kann eine lokale, temporäre PHP-Weiterleitung in den Projektcontainer genutzt werden; die Vite-Konfiguration bleibt dabei unverändert.

Migrations- und Rollbackprüfungen mit `migrate:fresh` dürfen ausschließlich auf einer eigens angelegten, entbehrlichen Prüfdatenbank laufen. Nach Migration, Rollback, erneuter Migration und wiederholtem Seeding müssen die Standardmethodik 16 Matrixzellen und der Gefährdungskatalog 47 Einträge enthalten. Eine Produktionsdatenbank darf dafür nicht zurückgesetzt werden.

Abgelaufene Fragekatalogvorschauen sind nicht mehr bestätigbar. Ihre verschlüsselten Payloads werden derzeit noch aufbewahrt; eine automatische Bereinigung ist separat zu ergänzen.
