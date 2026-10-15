<div align="center">

# 🧊 Personal Talos Kubernetes Cluster · b-a-l-a-z-s

*A self-hosted, GitOps-driven Kubernetes cluster built on Talos Linux and Proxmox VE, focused on reliability, observability, and clean automation.*

[![Proxmox](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/proxmox_version?format=shields&style=for-the-badge&logo=proxmox&logoColor=white&label=%20)](https://www.proxmox.com/)&nbsp;&nbsp;
[![TrueNAS](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/truenas_version?format=shields&style=for-the-badge&logo=truenas&logoColor=white&label=%20)](https://www.truenas.com/)&nbsp;&nbsp;
[![Talos](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/talos_version?format=shields&style=for-the-badge&logo=talos&logoColor=white&label=%20)](https://www.talos.dev/)&nbsp;&nbsp;
[![Kubernetes](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/kubernetes_version?format=shields&style=for-the-badge&logo=kubernetes&logoColor=white&label=%20)](https://kubernetes.io/)&nbsp;&nbsp;
[![Flux](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/flux_version?format=shields&style=for-the-badge&logo=flux&logoColor=white&label=%20)](https://fluxcd.io/)

[![Home-Internet](https://img.shields.io/uptimerobot/status/m804260968-e965d79e489ec74dffededdc?style=for-the-badge&logo=cloudflare&logoColor=white&label=Home%20Internet)](https://uptimerobot.com/)&nbsp;&nbsp;
[![Status-Page](https://img.shields.io/website?url=https%3A%2F%2Fstatus.b-a-l-a-z-s.workers.dev&style=for-the-badge&logo=statuspage&logoColor=white&label=Status%20Page&up_message=up&down_message=down)](https://status.b-a-l-a-z-s.workers.dev)&nbsp;&nbsp;
[![Alertmanager](https://img.shields.io/endpoint?url=https://healthchecks.io/b/2/7a2c7041-f05e-4c81-82fa-16781ded7346.shields&style=for-the-badge&logo=prometheus&logoColor=white&label=Alertmanager)](https://healthchecks.io/)

[![Age-Days](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/cluster_age_days?format=shields&style=flat-square&label=Age)](https://github.com/home-operations/kromgo)&nbsp;&nbsp;
[![Uptime-Days](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/cluster_uptime_days?format=shields&style=flat-square&label=Uptime)](https://github.com/home-operations/kromgo)&nbsp;&nbsp;
[![Node-Count](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/cluster_node_count?format=shields&style=flat-square&label=Nodes)](https://github.com/home-operations/kromgo)&nbsp;&nbsp;
[![Pod-Count](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/cluster_pod_count?format=shields&style=flat-square&label=Pods)](https://github.com/home-operations/kromgo)&nbsp;&nbsp;
[![Alerts](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/cluster_alert_count?format=shields&style=flat-square&label=Alerts)](https://github.com/home-operations/kromgo)&nbsp;&nbsp;
[![CPU-Usage](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/cluster_cpu_usage?format=shields&style=flat-square&label=CPU)](https://github.com/home-operations/kromgo)&nbsp;&nbsp;
[![Memory-Usage](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/cluster_memory_usage?format=shields&style=flat-square&label=Memory)](https://github.com/home-operations/kromgo)&nbsp;&nbsp;
[![Power-Usage](https://img.shields.io/endpoint?url=https://badges.b-a-l-a-z-s.workers.dev/badges/cluster_power_usage?format=shields&style=flat-square&label=Power)](https://github.com/home-operations/kromgo)

</div>

---

## 📌 Overview

This repository contains the full **GitOps-managed configuration** for my personal Kubernetes cluster.
The cluster runs on **Talos Linux** and is fully declarative: every component, application, and configuration is defined in Git and continuously reconciled by **FluxCD**.

The cluster is a **single-node Talos Linux virtual machine on a Proxmox VE host**. TrueNAS runs as a separate VM on the same host and provides the network storage.
The focus of this environment is learning, experimentation, and long-running homelab workloads rather than high availability.

It was bootstrapped with [TrueForge Clustertool](https://trueforge.org/), and most applications are [TrueCharts](https://trueforge.org/) Helm charts.

Key goals of this setup:

* 🔁 **Reproducibility** – rebuild the entire cluster from Git
* 🔒 **Immutability & Security** – minimal OS, no SSH, API-driven management, CrowdSec in front of everything public
* 📈 **Observability** – metrics, alerts to Discord, and a public status page
* 🤖 **Automation-first** – dependency updates are tested in CI and merged without manual intervention

---

## 🧠 Design Decisions

### Proxmox VE
- One machine runs the Talos node next to TrueNAS and other VMs
- The Talos VM can be restarted, reached from the console, or rebuilt without touching the rest of the host
- Proxmox is monitored from the cluster as well, so host and VM problems alert in Discord too

### Talos Linux
- Immutable, minimal OS reduces attack surface
- No SSH or package manager
- Fully API-driven, ideal for GitOps-based Kubernetes clusters

### FluxCD
- Continuous reconciliation instead of one-shot deployments
- Native Kubernetes integration, managed by the Flux Operator
- Works seamlessly with SOPS for encrypted secrets

### Single-Node Cluster
- Simplifies operations and reduces complexity
- Ideal for homelab and learning environments
- Focuses on reproducibility rather than high availability: nightly offsite backups make a rebuild cheap

---

## 📡 Networking

This cluster assumes a **simple and reliable home network environment**.

- The Talos VM is connected to the home network through a Proxmox bridge
- No advanced routing, BGP, or multi-homing is assumed; Cilium is the CNI and MetalLB gives services their LAN IPs
- Two Traefik instances: `traefik-internal` for LAN-only apps and `traefik-external` for public apps
- Public apps are reached through a Cloudflare Tunnel into `traefik-external`
- Blocky serves DNS for the internal hostnames
- Remote access goes through Tailscale (subnet router and exit node)

---

## 🛡️ Security

<div align="center">
  <img src="https://raw.githubusercontent.com/crowdsecurity/crowdsec-docs/main/crowdsec-docs/static/img/crowdsec_logo.png" alt="CrowdSec" width="200"/>
</div>

Everything that is reachable from the internet is protected by **[CrowdSec](https://www.crowdsec.net/)**:

~~~text
Internet → Cloudflare (WAF) → Cloudflare Tunnel → traefik-external + CrowdSec bouncer → app
~~~

- The CrowdSec bouncer (a Traefik plugin) runs on every route of `traefik-external`
- Fail-closed: if the bouncer can't load, Traefik drops the public routes instead of serving them unprotected
- The CrowdSec community blocklist blocks known bad IPs before they ever reach a login page; the instance is enrolled in the CrowdSec Console

---

## 🧩 Core Components

| Component                    | Description                                                                                                |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------- |
| **Proxmox VE**               | Hypervisor that hosts the Talos VM, TrueNAS, and other VMs                                                 |
| **Kubernetes**               | Container orchestration platform for running and managing workloads                                        |
| **Talos Linux**              | Immutable, API-driven Linux distribution purpose-built for Kubernetes                                      |
| **FluxCD**                   | GitOps operator used for continuous reconciliation of cluster state                                        |
| **Mend Renovate**            | Automatically tracks and updates charts, container images, and dependencies                                |
| **GitHub Actions**           | Installs every changed HelmRelease into a throwaway k3d cluster before an update is merged                 |
| **SOPS**                     | Encryption of all secrets and credentials stored in Git, integrated with FluxCD                            |
| **Clustertool**              | Bootstrap tool from TrueForge used to build the basic cluster structure and setup                          |
| **CrowdSec**                 | Detects and blocks attacks on the public apps                                                              |
| **Longhorn + VolSync**       | Block storage for app data, with nightly restic backups offsite                                            |
| **kube-prometheus-stack + Gatus** | Metrics and alerts, plus the public status page                                            |

---

## 🗂 Directory Structure

~~~text
.github/
├── workflows/        # HelmRelease test in k3d and the automerge queue
└── scripts/          # CI helper scripts

ci/                   # Dummy values and skip list for the HelmRelease test

clusters/
└── main/
    ├── flux-entry/   # Flux entry point: SOPS decryption and cluster-settings substitution for every Kustomization
    ├── kubernetes/   # Applications and Kubernetes workloads (apps, core, networking, observability, system, …)
    ├── secrets/      # SOPS-encrypted cluster-wide settings
    └── talos/        # Talos Linux machine and cluster configuration

repositories/
├── helm/             # Flux HelmRepository sources
└── oci/              # Flux OCIRepository sources

scripts/              # Helpers that run outside the cluster: Cloudflare Workers, Proxmox, and TrueNAS
~~~

---

## 🔐 Secrets Management

All secrets and credentials are stored in this repository **encrypted with SOPS** (age).

- Secrets are committed to Git in encrypted form
- Decryption happens inside the cluster via FluxCD
- Decryption keys are managed externally and are never stored in Git
- Each app keeps its own secrets next to it (`*.secret.yaml`); cluster-wide values live in `cluster-settings` and are substituted into the manifests by Flux
- A pre-commit hook refuses commits that contain unencrypted secrets
- This enables full GitOps workflows without exposing sensitive data

---

## ☁️ Cloud & External Dependencies

| Service              | Usage                                                                         |
| -------------------- | ----------------------------------------------------------------------------- |
| **Cloudflare**       | DNS, the tunnel for public apps, WAF rules, and Workers for the badges and status page |
| **GitHub**           | Source control, CI, and GitOps source                                         |
| **Let's Encrypt**    | TLS certificates through cert-manager                                         |
| **S3 storage**       | Offsite VolSync (restic) and CloudNativePG backups                            |
| **CrowdSec Console** | Community blocklist and security overview                                     |
| **Discord**          | Alerts from Alertmanager, Gatus, Proxmox, TrueNAS, and CrowdSec               |
| **Healthchecks.io**  | Dead man's switch for Alertmanager                                            |
| **UptimeRobot**      | Monitors the home internet connection                                         |

---

## 📊 Monitoring & Status

* 📈 **Metrics** via kube-prometheus-stack, which also scrapes Proxmox, TrueNAS (including disk SMART data), and the UPS power draw
* 🚨 **Alerting** to Discord from Alertmanager, Gatus, Proxmox, TrueNAS, and CrowdSec, with a Healthchecks.io dead man's switch in case Alertmanager itself goes quiet
* 🌍 **Service visibility** through the Gatus [status page](https://status.b-a-l-a-z-s.workers.dev) and UptimeRobot
* 🧮 **Cluster statistics** exposed via Kromgo and Shields.io, served through a Cloudflare Worker

---

## 🙏 Acknowledgements

This cluster is heavily inspired by and built upon the excellent work of:

* **TrueForge** – https://trueforge.org/
* **Home Operations** – https://github.com/home-operations

Their open-source contributions and documentation made this setup possible.

---

> ⚠️ **Note**  
> This repository is public for transparency and learning purposes.  
> Secrets and credentials **are stored in Git in encrypted form** using **SOPS**.  
> Decryption keys are managed externally and are **not** committed to the repository.

> 🧪 This cluster is used as a learning, testing, and long-running homelab environment.  
> Configurations may evolve as new Kubernetes, Talos, or GitOps features are evaluated.
