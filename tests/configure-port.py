#!/usr/bin/env python3
"""Portprüfung des Installers: Auch nach dem Stoppen des Dienstes (TIME_WAIT) frei, bei laufendem Programm belegt."""
import importlib.util, socket, sys, threading
from pathlib import Path

spec = importlib.util.spec_from_file_location('configure', Path(__file__).resolve().parent.parent / 'scripts' / 'configure.py')
cfg = importlib.util.module_from_spec(spec); spec.loader.exec_module(cfg)

def listen():
    server = socket.socket(); server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind(('0.0.0.0', 0)); server.listen(5); return server

# 1. Ein lauschendes Programm: Port belegt
server = listen(); port = server.getsockname()[1]
assert not cfg.port_free(port), 'belegter Port als frei erkannt'

# 2. Dienst stoppt, der Server schließt zuerst (TIME_WAIT auf der Serverseite): Port muss frei sein
client = socket.create_connection(('127.0.0.1', port)); conn, _ = server.accept()
conn.close(); client.recv(1); client.close()
server.close()
assert cfg.port_free(port), 'Port wegen TIME_WAIT fälschlich als belegt erkannt'
print('Portprüfung ok')
