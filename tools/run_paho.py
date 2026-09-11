#!/usr/bin/env python3
"""Run a Paho interoperability suite against an arbitrary host and port.

The suites set host/port as module globals inside their `if __name__ ==
"__main__"` block and then call unittest.main(), which re-parses sys.argv and
rejects the very -p/--port they just consumed. Importing the module and setting
those globals ourselves sidesteps that entirely.

Usage: run_paho.py <client_test|client_test5> <host> <port>
"""
import importlib
import sys
import unittest

name, host, port = sys.argv[1], sys.argv[2], int(sys.argv[3])

sys.path.insert(0, ".")
suite = importlib.import_module(name)

# Exactly what each suite's __main__ block establishes before unittest.main().
suite.host = host
suite.port = port
suite.nosubscribe_topics = ("test/nosubscribe",)

if name == "client_test5":
    prefix = "client_test5/"
    suite.topic_prefix = prefix
    suite.topics = [prefix + t for t in
                    ["TopicA", "TopicA/B", "Topic/C", "TopicA/C", "/TopicA"]]
    suite.wildtopics = [prefix + t for t in
                        ["TopicA/+", "+/C", "#", "/#", "/+", "+/+", "TopicA/#"]]
else:
    suite.topics = ("TopicA", "TopicA/B", "Topic/C", "TopicA/C", "/TopicA")
    suite.wildtopics = ("TopicA/+", "+/C", "#", "/#", "/+", "+/+", "TopicA/#")

print(f"### {name} against {host}:{port}", flush=True)

result = unittest.main(module=suite, argv=[name], exit=False).result
print(f"### {name}: ran {result.testsRun}, "
      f"failures={len(result.failures)}, errors={len(result.errors)}", flush=True)
sys.exit(0 if result.wasSuccessful() else 1)
