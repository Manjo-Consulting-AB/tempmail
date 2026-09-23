#!/usr/bin/env python3
"""
TempMail Python IMAP Fallback
Hämtar e-post via IMAP för Docker-miljö utan PHP IMAP-tillägg

Den här processen gör bara IMAP-arbetet. Den öppnar ingen databasanslutning och
kör ingen SQL själv: varje normaliserat meddelande skickas som JSON på stdin
till PHP-bryggan (python_imap_bridge.php, epic #169), som bygger IncomingEmail
och anropar EmailStorage::store(). All lagring för den här vägen ligger därmed i
tjänsten, på ett ställe, i stället för att dupliceras i Python.
"""

import sys
import os
import json
import subprocess
import imaplib
import email
from datetime import datetime, timedelta
from email.utils import parsedate_to_datetime
import re

# Statusar bryggan kan svara med (samma värden som StorageResult::STATUS_*).
BRIDGE_STATUSES = ('stored', 'duplicate', 'rejected', 'failed')


def log_message(level, message):
    """Logga meddelanden"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    print(f"[{timestamp}] [{level}] Python IMAP: {message}", file=sys.stderr)

def parse_imap_server(server_str):
    """Parse config.php's PHP-imap-style '{host:port/imap/ssl}MAILBOX' into (host, port)."""
    match = re.match(r'\{([^:/}]+)(?::(\d+))?', server_str or '')
    if not match:
        return None, None
    host = match.group(1)
    port = int(match.group(2)) if match.group(2) else 993
    return host, port

def connect_to_imap():
    """Anslut till IMAP-server (IMAP-uppgifterna kommer från miljövariabler,
    som PHP:s shell_exec() ärver från config.php - inga credentials i argv)."""
    try:
        host, port = parse_imap_server(os.environ.get('IMAP_SERVER', ''))
        user = os.environ.get('IMAP_USER', '')
        password = os.environ.get('IMAP_PASSWORD', '')
        if not host or not user or not password:
            log_message('ERROR', 'IMAP-inställningar saknas (IMAP_SERVER/IMAP_USER/IMAP_PASSWORD miljövariabler)')
            return None

        log_message('INFO', f'Ansluter till IMAP-server: {host}')

        # Anslut via SSL
        imap = imaplib.IMAP4_SSL(host, port)

        # Logga in
        imap.login(user, password)

        # Välj INBOX
        imap.select('INBOX')

        log_message('INFO', 'IMAP-anslutning lyckades')
        return imap

    except Exception as e:
        log_message('ERROR', f'IMAP-anslutning misslyckades: {e}')
        return None

def extract_recipient_address(email_msg):
    """Extrahera mottagaradress från e-post"""
    # Kolla To-header
    to_header = email_msg.get('To', '')
    if to_header:
        # Extrahera e-postadress med regex
        match = re.search(r'([a-f0-9]+)@manjo\.me', to_header, re.IGNORECASE)
        if match:
            return match.group(1)
    
    # Kolla Delivered-To eller X-Original-To headers
    for header in ['Delivered-To', 'X-Original-To', 'X-Envelope-To']:
        header_value = email_msg.get(header, '')
        if header_value:
            match = re.search(r'([a-f0-9]+)@manjo\.me', header_value, re.IGNORECASE)
            if match:
                return match.group(1)
    
    return None

def get_email_content(email_msg):
    """Hämta e-postinnehåll"""
    body_text = ''
    body_html = ''
    
    if email_msg.is_multipart():
        for part in email_msg.walk():
            content_type = part.get_content_type()
            if content_type == 'text/plain':
                body_text = part.get_payload(decode=True).decode('utf-8', errors='ignore')
            elif content_type == 'text/html':
                body_html = part.get_payload(decode=True).decode('utf-8', errors='ignore')
    else:
        content_type = email_msg.get_content_type()
        payload = email_msg.get_payload(decode=True)
        if payload:
            content = payload.decode('utf-8', errors='ignore')
            if content_type == 'text/html':
                body_html = content
            else:
                body_text = content
    
    return body_text, body_html

def store_email_via_bridge(email_data):
    """Lämna ett normaliserat meddelande till PHP-bryggan (epic #169).

    Returnerar bryggans status ('stored', 'duplicate', 'rejected', 'failed')
    eller None när bryggan inte kunde nås eller inte svarade med JSON. Anroparen
    behandlar None precis som 'failed': inget sparades, så meddelandet lämnas
    kvar på servern.

    Ingen shell-sträng och inga credentials i argv: bryggan skickas som en lista
    av argument till subprocess.run(), får sin databasanslutning från config.php
    och hittas via miljövariabler som php_imap_processor.php sätter
    (TEMPMAIL_PHP_BIN / TEMPMAIL_PYTHON_BRIDGE).
    """
    php_bin = os.environ.get('TEMPMAIL_PHP_BIN') or 'php'
    bridge_path = os.environ.get('TEMPMAIL_PYTHON_BRIDGE') or ''

    if not bridge_path:
        log_message('ERROR', 'Storage-bryggan är inte konfigurerad (TEMPMAIL_PYTHON_BRIDGE saknas)')
        return None
    if not os.path.isfile(bridge_path):
        log_message('ERROR', f'Storage-bryggan saknas: {bridge_path}')
        return None

    try:
        proc = subprocess.run(
            [php_bin, bridge_path],
            input=json.dumps(email_data),
            capture_output=True,
            text=True,
            check=False,
        )
    except OSError as e:
        log_message('ERROR', f'Kunde inte starta storage-bryggan: {e}')
        return None

    stdout = (proc.stdout or '').strip()
    try:
        result = json.loads(stdout)
    except ValueError:
        stderr = (proc.stderr or '').strip()
        log_message('ERROR', f'Storage-bryggan svarade inte med JSON (exit {proc.returncode}): {stderr or stdout}')
        return None

    if not isinstance(result, dict):
        log_message('ERROR', f'Storage-bryggan svarade med oväntat format: {stdout[:200]}')
        return None

    status = result.get('status')
    if status not in BRIDGE_STATUSES:
        log_message('ERROR', f'Storage-bryggan svarade med okänd status: {status!r}')
        return None

    return status

def process_emails(addresses):
    """Huvudfunktion för att processa e-post"""
    new_emails_count = 0

    # Anslut till IMAP
    imap = connect_to_imap()
    if not imap:
        return 0
    
    try:
        # För utveckling: Hämta alla meddelanden från senaste dagarna, inte bara olästa
        # I produktion kan vi ändra tillbaka till bara UNSEEN
        from datetime import datetime, timedelta
        
        # Sök först efter olästa meddelanden
        status, unseen_messages = imap.search(None, 'UNSEEN')
        unseen_ids = unseen_messages[0].split() if status == 'OK' else []
        
        # Om inga olästa, sök efter alla meddelanden från senaste 2 dagarna för utveckling
        if len(unseen_ids) == 0:
            two_days_ago = (datetime.now() - timedelta(days=2)).strftime('%d-%b-%Y')
            status, all_messages = imap.search(None, f'SINCE {two_days_ago}')
            all_ids = all_messages[0].split() if status == 'OK' else []
            
            # Kontrollera vilka som redan finns i databasen
            message_ids = []
            for msg_id in all_ids:
                # För utveckling, ta de senaste meddelandena
                message_ids.append(msg_id)
            
            log_message('INFO', f'Inga olästa meddelanden, hämtar {len(message_ids)} meddelanden från senaste 2 dagarna')
        else:
            message_ids = unseen_ids
            log_message('INFO', f'Hittade {len(message_ids)} olästa meddelanden')
        
        if status != 'OK':
            log_message('ERROR', 'Kunde inte söka meddelanden')
            return 0
        
        for msg_id in message_ids:
            try:
                # Hämta meddelande
                status, msg_data = imap.fetch(msg_id, '(RFC822)')
                
                if status != 'OK':
                    continue
                
                # Parsa e-post
                email_msg = email.message_from_bytes(msg_data[0][1])
                
                # Extrahera mottagaradress
                recipient_address = extract_recipient_address(email_msg)
                
                if not recipient_address or recipient_address not in addresses:
                    log_message('DEBUG', f'E-post för okänd adress, hoppar över')
                    continue
                
                # Extrahera e-postdata
                from_address = email_msg.get('From', '')
                subject = email_msg.get('Subject', '')
                date_header = email_msg.get('Date', '')
                
                # Parsa datum från email-header eller använd aktuell tid som fallback
                try:
                    if date_header:
                        received_time = parsedate_to_datetime(date_header)
                        received_at = received_time.strftime('%Y-%m-%d %H:%M:%S')
                    else:
                        received_at = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                except:
                    received_at = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
                
                body_text, body_html = get_email_content(email_msg)
                
                # Normaliserad e-post till lagringstjänsten
                email_data = {
                    'from_address': from_address,
                    'to_address': f'{recipient_address}@manjo.me',
                    'subject': subject,
                    'body_text': body_text,
                    'body_html': body_html,
                    'received_at': received_at
                }
                
                # Spara via PHP-bryggan (EmailStorage::store())
                status_result = store_email_via_bridge(email_data)

                if status_result == 'stored':
                    new_emails_count += 1
                    log_message('INFO', f'E-post sparad för {recipient_address}')
                    
                    # Markera som raderad från servern
                    imap.store(msg_id, '+FLAGS', '\\Deleted')
                    log_message('DEBUG', f'Meddelande {msg_id} markerat för radering')
                elif status_result == 'duplicate':
                    log_message('DEBUG', 'E-post redan finns i databasen, hoppar över')
                else:
                    # 'failed', 'rejected' eller None: inget sparades, så
                    # meddelandet ligger kvar på servern för ett nytt försök.
                    log_message('WARNING', f'E-post kunde inte sparas (status={status_result or "unavailable"}), meddelandet behålls på servern')
                
            except Exception as e:
                log_message('ERROR', f'Fel vid bearbetning av meddelande {msg_id}: {e}')
                continue
        
        # Verkställ radering av markerade meddelanden
        if new_emails_count > 0:
            imap.expunge()
            log_message('INFO', f'Raderade {new_emails_count} meddelanden från servern')
    
    finally:
        imap.close()
        imap.logout()
    
    return new_emails_count

def main():
    """Huvudfunktion"""
    if len(sys.argv) != 2:
        print(json.dumps({'error': 'Usage: python_imap_fallback.py <addresses_file>'}))
        sys.exit(1)
    
    addresses_file = sys.argv[1]
    
    try:
        # Läs adresser från fil
        with open(addresses_file, 'r') as f:
            addresses = json.load(f)
        
        log_message('INFO', f'Startar Python IMAP-processor för {len(addresses)} adresser')
        
        # Processa e-post
        new_emails = process_emails(addresses)
        
        # Returnera resultat som JSON
        result = {
            'success': True,
            'new_emails': new_emails,
            'message': f'Processed {new_emails} new emails'
        }
        
        print(json.dumps(result))
        
    except Exception as e:
        log_message('ERROR', f'Huvudfel: {e}')
        error_result = {
            'success': False,
            'new_emails': 0,
            'message': str(e)
        }
        print(json.dumps(error_result))

if __name__ == '__main__':
    main()