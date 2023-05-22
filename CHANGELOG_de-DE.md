# 4.0.53
- Erstellung einer neuen Spalte in der Transaktionstabelle mit dem Namen erp_merchant_id

# 4.0.52
- Unterstützung von Shopware 6.4.20.1

# 4.0.51
- Falsches Linkformat in der Fehlermeldung.

# 4.0.50
- Steuerinformationen wurden von der Versands- zu der Rechnungsstellung verschoben.
- Teilweise war die Synchronisierung der Portal Daten zu SW6 unvollständig.
- Lösen eines Fehlers: Der bezahlte Betrag im Portal wurde nicht an SW6 gemeldet.
- Lösen eines Fehlers: Nach Wechsel der Zahlungsmethode im Checkout, wurde der Zahlungsstatus auf "bezahlt" gesetzt.
- Lösen eines Fehlers: Beim Auflisten der Zahlungsmethoden durch den Kunden im Checkout Prozess.

# 4.0.45
- Fügen Sie zusätzliche Informationen der Kreditkarte (Gültigkeitsdatum, Pseudo-Kreditkartennummer und PayID) für Transaktionen mit dieser Zahlungsmethode hinzu
- Getestet mit SW v6.4.17.1
- 
# 4.0.29
- Korrektur zum Ausblenden des Geburtsdatumsfelds, wenn es bereits vorhanden ist
- Getestet mit SW v6.4.9.0

# 4.0.28
- Italienische Übersetzungen hinzugefügt
- Getestet mit SW v6.4.9.0

# 4.0.26
- Dokumentation zum Flow Builder hinzugefügt

# 4.0.25
- Die Handhabung der sofortigen Zahlung von Transaktionsrechnungen wurde korrigiert.
- Getestet mit v6.4.7.0

# 4.0.24
- Rückerstattungen nach Betrag hinzugefügt

# 4.0.23
- Korrigierte Warenkorb-Neuerstellungsfunktion für benutzerdefinierte Produkte

# 4.0.22
- Unterstützung für Französisch hinzugefügt

# 4.0.21
- Benutzerdefinierte Produktoptionen werden als separate Einzelposten angezeigt

# 4.0.20
- Fester Firmenname für Lieferadresse

# 4.0.17
- Einstellungen zum Importieren von Webhooks und Zahlungsmethoden korrigiert

# 4.0.16
- Einstellungen zur Steuerung der Aktualisierung von Webhooks und Zahlungsmethoden hinzugefügt

# 4.0.15
- Wallee/SW6-Dokumentation anpassen – wie man Rückerstattungen durchführt

# 4.0.14
- Unterstützung für Shopware 6.4.6

# 4.0.13
- Loader Chrome IOS beheben

# 4.0.12
- Implementierte Sicherheitskorrektur

# 4.0.11
- Automatisches Senden bei leerem iframe zurückgesetzt, da es nicht in allen Fällen richtig funktioniert

# 4.0.10
- Das Verhalten der Option "Zahlungsänderung nach der Kasse zulassen" behoben

# 4.0.9
- Erlaube, den Zahlungsstatus als bezahlt ab Status erinnert zu markieren

# 4.0.8
- Automatische Übermittlung des Checkout-Formulars implementiert, wenn iFrame keine Eingabefelder zurückgibt

# 4.0.7
- Behebung des Transaktions-Rollback-Fehlers in nicht unterstützten Sprachen

# 4.0.6
- Fehler beim Ändern des Lieferstatus behoben

# 4.0.5
- Deinstallation Aktion des Plugins behoben

# 4.0.4
- Erstattungen von Werbebuchungen

# 4.0.3
- Aktualisieren Sie das SDK

# 4.0.2
- Der Name der Versand-Einzelposten wurde korrigiert

# 4.0.1
- Feste Steuerberechnung für kundenspezifische Produkte

# 4.0.0
- Unterstützung für Shopware 6.4

# 3.1.0
- Unterstützung für Custom Products Plugin

# 3.0.0
- Korrigieren Sie die Transaktionsversionierung
- Aktualisieren Sie das SDK

# 2.1.1
- Runde Beträge
- Weiterleiten, wenn der Wagen nicht neu erstellt werden kann

# 2.1.0
- E-Mail-Probleme behoben

# 2.0.0
- Warenkorb-Wiederherstellung bei Werbeaktionen korrigiert
- Verfügbarkeitsregeln entfernt
- Verbessertes Behandeln von Aufträge kleiner oder gleich Null

# 1.4.3
- Fehlende Webhook-Fehler ausschließen
- Iframe-Ausbruch behoben

# 1.4.2
- Behebung des Fehlers bei der Zahlungsmethode bei der Erstinstallation

# 1.4.1
- Rufen Sie nur aktive Zahlungsmethoden ab

# 1.4.0
- Festlegen der Verfügbarkeitsregel für Zahlungsmethoden
- E-Mail-Versand korrigiert
- Fehlgeschlagene Bestellungen stornieren

# 1.3.0
- Aktualisieren Sie die Synchronisierung der Zahlungsmethode

# 1.2.0
- Verfügbarkeitsregel für Zahlungsmethoden hinzufügen
- Hardcodierte Systemsprachen

# 1.1.27
- Wiederholen Sie Bestellungen bei nicht verfügbarer Zahlungsmethode

# 1.1.26
- Korrigieren Sie Gebietsschemas und Übersetzungen

# 1.1.25
- E-Mail-Versand korrigiert

# 1.1.24
- Webhook-Antwort korrigiert
- Übersetzung korrigieren
- Bereiten Sie sich auf Shopware vor 6.4

# 1.1.23
- Senden Sie das Zahlungsformular, wenn iframe keine Felder enthält

# 1.1.22
- Einstellung zum Herunterladen der Bestellrechnung

# 1.1.21
- Entfernen Sie die fest codierte Shopware-API-Version

# 1.1.20
- Aktualisieren Sie die Webhook-URLs beim Plugin-Update
- Übersetzungen hinzufügen
- E-Mail-Fehler behoben

# 1.1.19
- Kunden können Bestellrechnungen herunterladen

# 1.1.18
- Test gegen Shopware 6.3
- Fehler bei ungültiger Speicherplatz-ID behoben
- Entfernen Sie die fest codierte Shopware-API-Version

# 1.1.17
- Verwenden Sie DAL für Webhook-Sperren

# 1.1.16
- Stellen Sie nur Übersetzungen für verfügbare Sprachen bereit
- CustomerCanceledAsyncPaymentException für stornierte Transaktionen zurückgeben
- Aktualisieren Sie das SDK auf 2.1.1

# 1.1.15
- Senden Sie den Vor- und Nachnamen des Kunden aus den Rechnungs- und Versandprofilen
- Respektieren Sie die Shop-URL

# 1.1.14
- Fügen Sie dem Cookie-Manager Cookies hinzu
- Ändern Sie die Größe des Symbols auf 40px * 40px
- Korrektur von Werbebuchungsattributen

# 1.1.13
- Fügen Sie den Lieferantenordner in Shopware Store-Versionen ein

# 1.1.12
- Dokumentpfad aktualisieren

# 1.1.11
- Dokumentation hinzufügen

# 1.1.10
- Reagieren Sie nicht mehr mit Serverfehlern, wenn keine Bestellungen gefunden werden

# 1.1.9
- Setzen Sie try catch auf die Webhook-Installation

# 1.1.8
- Entfernen Sie nicht hilfreiche Ticketinformationen in den Release-Kommentaren

# 1.1.7
- Werbeaktionen durchführen
- Code Refactoring

# 1.1.6
- Deaktivieren Sie die Auswahl der Vertriebskanäle für Vitrinen
- Fügen Sie der Transaktionsnutzlast Produktattribute hinzu

# 1.1.5
- Einstellungsfehler behoben

# 1.1.4
- Deaktivieren Sie das Ändern der Anmeldeinformationen für die Vitrinen

# 1.1.3
- Legen Sie die Konsistenz der Werbebuchung als Standard fest
- Bestätigen Sie die Transaktion sofort
- Aktualisieren Sie die Einstellungsbeschreibungen

# 1.1.2
- Bereiten Sie die interne serverseitige Installation für Vitrinen und Demos vor

# 1.1.1
- Stoppen Sie das Senden von Standard-E-Mails
- Verschönern Sie die Zahlungsseite

# 1.1.0
- Behandeln Sie leere / Standardeinstellungswerte
- Speichern Sie Rückerstattungen in db und laden Sie die Registerkarte Bestellung bei Änderungen neu

# 1.0.0
- Erste Version der PostFinanceCheckout-Integrationen für Shopware 6
