import pandas as pd
import pickle

from sklearn.ensemble import RandomForestClassifier

from sqlalchemy import create_engine


DATABASE_URL = (
    "mysql+pymysql://root:@localhost/"
    "dental_ai_system"
)

engine = create_engine(DATABASE_URL)


query = """
SELECT
    patient_id,

    COUNT(*) AS total_appointments,

    SUM(
        CASE
            WHEN status = 'No-Show'
            THEN 1
            ELSE 0
        END
    ) AS no_shows,

    SUM(
        CASE
            WHEN status = 'Cancelled'
            THEN 1
            ELSE 0
        END
    ) AS cancellations,

    SUM(
        CASE
            WHEN status = 'Completed'
            THEN 1
            ELSE 0
        END
    ) AS completed

FROM appointments

GROUP BY patient_id
"""

df = pd.read_sql(query, engine)


if len(df) < 5:

    print(
        "Not enough historical data "
        "to train Random Forest."
    )

    exit()


df["no_show_rate"] = (
    df["no_shows"] /
    df["total_appointments"]
)


df["cancel_rate"] = (
    df["cancellations"] /
    df["total_appointments"]
)


df["target"] = (
    df["no_show_rate"] >= 0.5
).astype(int)


features = [
    "total_appointments",
    "no_shows",
    "cancellations",
    "completed",
    "no_show_rate",
    "cancel_rate"
]


X = df[features]

y = df["target"]


model = RandomForestClassifier(
    n_estimators=100,
    random_state=42,
    class_weight="balanced"
)


model.fit(X, y)


with open(
    "model.pkl",
    "wb"
) as file:

    pickle.dump(
        model,
        file
    )


print(
    "Random Forest model successfully trained."
)
