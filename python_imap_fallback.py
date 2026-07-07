#!/usr/bin/env python3
"""
TempMail Python IMAP Fallback
Hämtar e-post via IMAP för Docker-miljö utan PHP IMAP-tillägg
"""

import sys
import os
import json
import imaplib
import email
import mysql.connector
from datetime import datetime, timedelta
from email.utils import parsedate_to_datetime
import re

def log_message(level, message):
    """Logga meddelanden"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    print(f"[{timestamp}] [{level}] Python IMAP: {message}", file=sys.stderr)

def connect_to_database():
    """Anslut till MySQL-databasen.

    Credentials come from environment variables only - this script is invoked
    via PHP's shell_exec(), which inherits the parent process's environment
    (populated by config.php's loadEnvironmentVariables()/putenv() from the
    .env file), so no secrets need to be hardcoded or passed as arguments.
    """
    try:
        conn = mysql.connector.connect(
            host=os.environ.get('DB_HOST', 'localhost'),
            port=int(os.environ.get('DB_PORT') or 3306),
            database=os.environ.get('DB_NAME', 'tempmail'),
            user=os.environ.get('DB_USER', ''),
            password=os.environ.get('DB_PASSWORD', '')
        )
        return conn
    except mysql.connector.Error as e:
        log_message('ERROR', f'Databasanslutning misslyckades: {e}')
        return None

def parse_imap_server(server_str):
    """Parse config.php's PHP-imap-style '{host:port/imap/ssl}MAILBOX' into (host, port)."""
    match = re.match(r'\{([^:/}]+)(?::(\d+))?', server_str or '')
    if not match:
        return None, None
    host = match.group(1)
    port = int(match.group(2)) if match.group(2) else 993
    return host, port

def connect_to_imap():
    """Anslut till IMAP-server (credentials from environment variables - see connect_to_database)."""
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

def email_exists_in_database(db_conn, from_address, to_address, subject, received_at):
    """Kontrollera om e-post redan finns i databasen"""
    try:
        cursor = db_conn.cursor()
        
        query = """
        SELECT COUNT(*) FROM stored_emails 
        WHERE from_address = %s AND to_address = %s AND subject = %s 
        AND ABS(TIMESTAMPDIFF(MINUTE, received_at, %s)) < 5
        """
        
        cursor.execute(query, (from_address, to_address, subject, received_at))
        count = cursor.fetchone()[0]
        cursor.close()
        
        return count > 0
        
    except mysql.connector.Error as e:
        log_message('ERROR', f'Kunde inte kontrollera e-post: {e}')
        return False

def save_email_to_database(db_conn, email_data):
    """Spara e-post till databasen"""
    try:
        # Kontrollera om e-posten redan finns
        if email_exists_in_database(db_conn, 
                                   email_data['from_address'],
                                   email_data['to_address'], 
                                   email_data['subject'],
                                   email_data['received_at']):
            log_message('DEBUG', f'E-post redan finns i databasen, hoppar över')
            return False
        
        cursor = db_conn.cursor()

        # Determine expires_at by looking up temp_emails for the local part (if available)
        # If this is a personal address (pro_user_id), compute expires based on pro_users.address_ttl_days
        expires_at = None
        try:
            local = email_data['to_address'].split('@')[0].lower()
            lookup_q = "SELECT id, expires_at, pro_user_id FROM temp_emails WHERE unique_address = %s LIMIT 1"
            cursor.execute(lookup_q, (local,))
            row = cursor.fetchone()
            if row:
                # row now: id, expires_at, pro_user_id
                temp_email_id = row[0]
                pro_user_id = row[2]
                if pro_user_id:
                    try:
                        # Fetch pro user's TTL
                        ttl_q = "SELECT COALESCE(address_ttl_days, 1) FROM pro_users WHERE id = %s LIMIT 1"
                        cursor.execute(ttl_q, (pro_user_id,))
                        ttl_row = cursor.fetchone()
                        if ttl_row and ttl_row[0] is not None:
                            ttl_days = int(ttl_row[0])
                            if ttl_days < 1:
                                ttl_days = 1
                            # Use received_at as base
                            try:
                                base_dt = datetime.strptime(email_data['received_at'], '%Y-%m-%d %H:%M:%S')
                            except Exception:
                                base_dt = datetime.now()
                            expires_at = (base_dt + timedelta(days=ttl_days)).strftime('%Y-%m-%d %H:%M:%S')
                    except Exception as e:
                        log_message('WARNING', f'Could not fetch pro user TTL for {pro_user_id}: {e}')
                # Fallback to temp_emails.expires_at when not personal or TTL fetch failed
                if not expires_at and row[0]:
                    expires_at = row[0]
        except Exception as e:
            log_message('WARNING', f'Could not lookup expires_at/pro_user for {email_data["to_address"]}: {e}')

        # Include temp_email_id when known so DB FK cascade can remove messages when addresses are deleted
        query = """
        INSERT INTO stored_emails (from_address, to_address, subject, body_text, body_html, received_at, expires_at, temp_email_id)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        """

        cursor.execute(query, (
            email_data['from_address'],
            email_data['to_address'],
            email_data['subject'],
            email_data['body_text'],
            email_data['body_html'],
            email_data['received_at'],
            expires_at,
            locals().get('temp_email_id', None)
        ))
        
        db_conn.commit()
        cursor.close()
        
        # Uppdatera statistik
        update_stat(db_conn, 'emails_processed', 1)
        
        return True
        
    except mysql.connector.Error as e:
        log_message('ERROR', f'Kunde inte spara e-post: {e}')
        return False

def update_stat(db_conn, stat_name, increment=1):
    """Uppdatera statistik i databasen"""
    try:
        cursor = db_conn.cursor()
        
        query = """
        INSERT INTO email_stats (stat_name, stat_value) 
        VALUES (%s, %s) 
        ON DUPLICATE KEY UPDATE 
        stat_value = stat_value + VALUES(stat_value),
        last_updated = CURRENT_TIMESTAMP
        """
        
        cursor.execute(query, (stat_name, increment))
        db_conn.commit()
        cursor.close()
        
        log_message('DEBUG', f'Statistik uppdaterad: {stat_name} +{increment}')
        return True
        
    except mysql.connector.Error as e:
        log_message('ERROR', f'Kunde inte uppdatera statistik: {e}')
        return False

def process_emails(addresses):
    """Huvudfunktion för att processa e-post"""
    new_emails_count = 0
    
    # Anslut till databas
    db_conn = connect_to_database()
    if not db_conn:
        return 0
    
    # Anslut till IMAP
    imap = connect_to_imap()
    if not imap:
        db_conn.close()
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
                
                # Förbered data för databas
                email_data = {
                    'from_address': from_address,
                    'to_address': f'{recipient_address}@manjo.me',
                    'subject': subject,
                    'body_text': body_text,
                    'body_html': body_html,
                    'received_at': received_at
                }
                
                # Spara till databas
                if save_email_to_database(db_conn, email_data):
                    new_emails_count += 1
                    log_message('INFO', f'E-post sparad för {recipient_address}')
                    
                    # Markera som raderad från servern
                    imap.store(msg_id, '+FLAGS', '\\Deleted')
                    log_message('DEBUG', f'Meddelande {msg_id} markerat för radering')
                
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
        db_conn.close()
    
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