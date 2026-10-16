$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$ds=@{type='prometheus';uid='attendance-prometheus'}
$panels=@()
$specs=@(
    @('Container CPU', 'percent', '100 * sum by (container_label_com_docker_compose_service) (rate(container_cpu_usage_seconds_total{job="cadvisor",container_label_com_docker_compose_project="attendance-phase1-fresh"}[2m]))', '{{container_label_com_docker_compose_service}}'),
    @('Container RAM (working set)', 'bytes', 'sum by (container_label_com_docker_compose_service) (container_memory_working_set_bytes{job="cadvisor",container_label_com_docker_compose_project="attendance-phase1-fresh"})', '{{container_label_com_docker_compose_service}}'),
    @('Container network receive', 'Bps', 'sum by (container_label_com_docker_compose_service) (rate(container_network_receive_bytes_total{job="cadvisor",container_label_com_docker_compose_project="attendance-phase1-fresh",interface!="lo"}[2m]))', '{{container_label_com_docker_compose_service}}'),
    @('Container network transmit', 'Bps', 'sum by (container_label_com_docker_compose_service) (rate(container_network_transmit_bytes_total{job="cadvisor",container_label_com_docker_compose_project="attendance-phase1-fresh",interface!="lo"}[2m]))', '{{container_label_com_docker_compose_service}}'),
    @('Nginx requests per second', 'reqps', 'rate(nginx_http_requests_total{job="nginx"}[2m])', 'Nginx'),
    @('MySQL connections (current)', 'short', 'mysql_global_status_threads_connected{job="mysql"}', 'Connected'),
    @('Nginx active connections', 'short', 'nginx_connections_active{job="nginx"}', 'Active'),
    @('MySQL total connections', 'short', 'mysql_global_status_connections{job="mysql"}', 'Total since start'),
    @('Prometheus scrape target health', 'short', 'up', '{{job}}')
)
for ($i=0;$i -lt $specs.Count;$i++) {
    $s=$specs[$i]
    $panels+=@{id=$i+1;title=$s[0];type='timeseries';datasource=$ds;gridPos=@{x=($i%2)*12;y=[int][Math]::Floor($i/2)*8;w=12;h=8};targets=@(@{refId='A';expr=$s[2];legendFormat=$s[3];range=$true});fieldConfig=@{defaults=@{unit=$s[1];min=0};overrides=@()};options=@{legend=@{displayMode='list';placement='bottom'};tooltip=@{mode='multi'}}}
}
$dashboard=@{uid='attendance-system';title='Attendance System Monitoring';schemaVersion=39;version=1;editable=$false;tags=@('attendance','phase3');timezone='Asia/Ho_Chi_Minh';refresh='15s';time=@{from='now-15m';to='now'};panels=$panels;annotations=@{list=@()};templating=@{list=@()}}
$path=Join-Path $root 'grafana/dashboards/attendance-system.json'
[IO.File]::WriteAllText($path,($dashboard | ConvertTo-Json -Depth 30),(New-Object Text.UTF8Encoding($false)))
Write-Output "Generated dashboard with $($panels.Count) real PromQL panels."
