import importlib.util
from pathlib import Path
import unittest
spec=importlib.util.spec_from_file_location('arm_collector',Path(__file__).parents[2]/'ops/monitoring/collector.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class CollectorTests(unittest.TestCase):
    def test_status_histogram_and_no_sensitive_labels(self):
        counts,buckets,total,n=m.parse_nginx('arm_metrics 100.500 200 0.100\narm_metrics 101.000 503 2.000',100,102)
        self.assertEqual(dict(counts),{'2xx':1,'5xx':1});self.assertEqual(n,2)
        self.assertEqual(buckets['0.1'],1);self.assertEqual(buckets['2.5'],2)
        self.assertEqual(buckets['+Inf'],n);self.assertAlmostEqual(total,2.1)
    def test_interval_boundaries_do_not_duplicate(self):
        text='arm_metrics 100.000 200 0.05\narm_metrics 101.000 200 0.05'
        self.assertEqual(m.parse_nginx(text,100,101)[3],1)
        self.assertEqual(m.parse_nginx(text,101,102)[3],1)
    def test_ignore_other_logs_and_injected_fields(self):
        text='client personal data\narm_metrics 100.000 200 0.1 /private?token=x\narm_metrics 100.100 999 0.1\narm_metrics 100.200 200 -1'
        self.assertEqual(m.parse_nginx(text,100,101)[3],0)
    def test_cpu_ratio_and_reset(self):
        self.assertAlmostEqual(m.cpu_usage([10,0,0,80,10,0,0,0],[20,0,0,170,10,0,0,0]),10)
        self.assertEqual(m.cpu_usage(None,[1]*8),0)
        self.assertEqual(m.cpu_usage([1]*8,[0]*8),0)
    def test_redirects_are_never_followed(self):
        self.assertIsNone(m.NoRedirect().redirect_request(None,None,302,'',{},'https://other.invalid'))
    def test_php_has_no_user_data_or_sql_text_query(self):
        self.assertNotIn('SELECT query',m.PHP);self.assertIn('statement_timeout',m.PHP)
        self.assertNotIn('user_profiles',m.PHP)

if __name__=='__main__':unittest.main()
