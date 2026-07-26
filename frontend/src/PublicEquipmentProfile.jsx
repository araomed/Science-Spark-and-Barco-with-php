import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import api from "./api";

function display(value) {
  return value === null || value === undefined || value === "" ? "Not set" : value;
}

function formatDate(value) {
  if (!value) return "Not set";
  return new Intl.DateTimeFormat("en", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  }).format(new Date(`${value}T00:00:00`));
}

function PublicEquipmentProfile() {
  const { id } = useParams();
  const [profile, setProfile] = useState(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let isCurrent = true;
    setError("");
    setProfile(null);

    api.get(`/public/instruments/${id}/profile`)
      .then((response) => {
        if (isCurrent) {
          setProfile(response.data);
        }
      })
      .catch(() => {
        if (isCurrent) {
          setError("This equipment profile could not be opened.");
        }
      });

    return () => {
      isCurrent = false;
    };
  }, [id]);

  if (error) {
    return (
      <main className="public-scan-page">
        <section className="public-scan-shell">
          <p className="eyebrow">Science Spark Equipment</p>
          <h1>Profile unavailable</h1>
          <p>{error}</p>
        </section>
      </main>
    );
  }

  if (!profile) {
    return (
      <main className="public-scan-page">
        <section className="public-scan-shell">
          <p className="eyebrow">Science Spark Equipment</p>
          <h1>Loading profile...</h1>
        </section>
      </main>
    );
  }

  const instrument = profile.instrument;
  const details = [
    ["Model", instrument.model],
    ["Serial", instrument.serial_number],
    ["Manufacturer", instrument.manufacturer],
    ["Location", instrument.location],
    ["Status", instrument.status],
    ["Purchase Date", formatDate(instrument.purchase_date)],
  ];

  return (
    <main className="public-scan-page">
      <div className="public-scan-shell">
        <section className="public-scan-hero">
          <div>
            <p className="eyebrow">Science Spark Equipment</p>
            <h1>{display(instrument.name)}</h1>
            <p>Read-only QR profile</p>
          </div>
          <span className="scan-status">{display(instrument.status)}</span>
        </section>

        <section className="public-scan-card">
          <h2>Details</h2>
          <div className="public-detail-grid">
            {details.map(([label, value]) => (
              <div key={label}>
                <span>{label}</span>
                <strong>{display(value)}</strong>
              </div>
            ))}
          </div>
        </section>

        <section className="public-scan-card">
          <h2>Recent Maintenance</h2>
          <div className="public-list">
            {profile.maintenance_records.length ? (
              profile.maintenance_records.map((record) => (
                <p key={record.id}>
                  <strong>{formatDate(record.date)}</strong>
                  <span>{display(record.type)} by {display(record.technician)}</span>
                </p>
              ))
            ) : (
              <p><strong>No records found</strong><span>Nothing to show yet</span></p>
            )}
          </div>
        </section>

        <section className="public-scan-card">
          <h2>Recent Service Reports</h2>
          <div className="public-list">
            {profile.service_reports.length ? (
              profile.service_reports.map((report) => (
                <p key={report.id}>
                  <strong>{formatDate(report.date)}</strong>
                  <span>{display(report.technician)}</span>
                </p>
              ))
            ) : (
              <p><strong>No records found</strong><span>Nothing to show yet</span></p>
            )}
          </div>
        </section>

        <Link className="primary-action public-dashboard-link" to="/equipment">Open dashboard</Link>
      </div>
    </main>
  );
}

export default PublicEquipmentProfile;
