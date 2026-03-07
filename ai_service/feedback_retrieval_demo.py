from feedback_retrieval_system import build_demo_system


def main() -> None:
    system = build_demo_system()
    query = {
        "strengths": "The teacher gives clear explanations and keeps lessons easy to follow.",
        "areas_for_improvement": "The teacher explains lessons clearly but students rarely participate.",
        "recommendations": "Students rarely participate and need more opportunities to engage in the lesson.",
        # "agreement": "The teacher agreed to improve learner participation during the next observation period.",  # Excluded from demo query
    }
    results = system.retrieve_feedback_for_form(query)

    for field_name, template in results.items():
        print(f"\n{field_name.upper()}")
        print("-" * len(field_name))
        if template is None:
            print("No match found")
            continue
        print(f"Query: {query[field_name]}")
        print(f"Best match: {template.feedback_text}")
        print(f"Similarity: {template.similarity:.4f}")

    system.close()


if __name__ == "__main__":
    main()
